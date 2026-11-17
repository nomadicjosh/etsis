<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArrayObject;
use Domain\Admissions\ApplicationForm;
use Domain\Admissions\ValueObject\ApplicationFieldSettings;
use Domain\Admissions\ValueObject\ApplicationFormLayout;
use Domain\Admissions\ValueObject\SubmissionIntegrity;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Runtime\PropertyHook;
use PHPUnit\Framework\TestCase;
use PDO;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Qubus\Expressive\Schema;
use Qubus\Expressive\Schema\AlterTable;

final class ApplicationFormLayoutTest extends TestCase
{
    private function form(array $fields, array $layout = []): ApplicationForm
    {
        return new ApplicationForm(
            new EntityId('01K00000000000000000000001'),
            new EntityId('01K00000000000000000000002'),
            'Admission',
            $fields,
            layout: $layout
        );
    }

    public function testLegacyFormsRetainTheirDefinitionShapeAndDigest(): void
    {
        $form = $this->form([['key' => 'name', 'type' => 'text', 'label' => 'Name']]);
        self::assertSame([], $form->layout);
        self::assertSame(
            ['key', 'type', 'label', 'help', 'required', 'options', 'show_when'],
            array_keys($form->fields[0])
        );
        self::assertSame(
            'ed1880a685372b36e04d8c1e5c3576292503ccd6602c0158df48d756196cf910',
            SubmissionIntegrity::formHash($form)
        );
    }

    public function testContainersRequireCompleteUniqueReadingOrderPlacement(): void
    {
        $layout = [['key' => 'about_you', 'label' => 'About you', 'columns' => [['name'], ['email']]],
            ['key' => 'study', 'label' => 'Study plans', 'columns' => [['program']]]];
        $value = new ApplicationFormLayout($layout, ['name', 'email', 'program']);
        self::assertSame('', $value->containers[0]['help']);
        self::assertSame([['name'], ['email']], $value->containers[0]['columns']);
        foreach ([['email', 'name', 'program'], ['name', 'email'], ['name', 'email', 'email']] as $keys) {
            try {
                new ApplicationFormLayout($layout, $keys);
                self::fail('Invalid question placement was accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        new ApplicationFormLayout([['key' => 'about_you', 'label' => 'Invalid', 'columns' => [[], [], [], []]]], []);
    }

    public function testLayoutIsPartOfTheImmutableFormDigest(): void
    {
        $fields = [['key' => 'name', 'label' => 'Name', 'type' => 'text']];
        $first = $this->form($fields, [['key' => 'about', 'label' => 'About you', 'columns' => [['name']]]]);
        $second = $this->form($fields, [['key' => 'about', 'label' => 'Your details', 'columns' => [[], ['name']]]]);
        self::assertNotSame(SubmissionIntegrity::formHash($first), SubmissionIntegrity::formHash($second));
    }

    public function testSettingsAndRadioConditionsValidateWithoutAcceptingHiddenAnswers(): void
    {
        $form = $this->form([
            ['key' => 'study', 'label' => 'Previous study', 'type' => 'radio', 'required' => true,
                'options' => ['First application', 'Transfer']],
            ['key' => 'statement', 'label' => 'Statement', 'type' => 'textarea', 'required' => true,
                'settings' => ['placeholder' => 'Tell us about yourself', 'min_length' => 3, 'max_length' => 5]],
            ['key' => 'credits', 'label' => 'Credits', 'type' => 'number', 'required' => true,
                'settings' => ['min_value' => 1, 'max_value' => 6],
                'show_when' => ['condition' => 'AND', 'rules' => [
                    ['id' => 'study', 'operator' => 'equal', 'value' => 'Transfer'],
                ]]],
        ]);
        self::assertSame(
            ['study' => 'First application', 'statement' => '😀😀😀'],
            $form->validate(['study' => 'First application', 'statement' => '😀😀😀', 'credits' => 'hidden invalid value'])
        );
        $answers = $form->validate(['study' => 'Transfer', 'statement' => 'hello', 'credits' => 6]);
        self::assertSame(6, $answers['credits']);
        try {
            $form->validate(['study' => 'Transfer', 'statement' => 'hi', 'credits' => 7]);
            self::fail('Question bounds were not enforced.');
        } catch (ValidationException $error) {
            self::assertStringNotContainsString('hidden invalid value', $error->getMessage());
        }
        $this->expectException(ValidationException::class);
        $form->validate(['study' => 'Unknown choice', 'statement' => 'hello']);
    }

    public function testDateLimitsAreInclusiveAndInvalidDatesAreValidationErrors(): void
    {
        $form = $this->form([['key' => 'date', 'type' => 'date', 'label' => 'Date', 'required' => true,
            'settings' => ['earliest_date' => '2028-02-28', 'latest_date' => '2028-03-01']]]);
        foreach (['2028-02-28', '2028-02-29', '2028-03-01'] as $date) {
            self::assertSame(['date' => $date], $form->validate(['date' => $date]));
        }
        foreach (["2028-02-29\0", '2027-02-29', '2028-03-02', '0000-01-01'] as $date) {
            try {
                $form->validate(['date' => $date]);
                self::fail('An invalid date was accepted.');
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
    }

    public static function invalidSettings(): iterable
    {
        yield ['text', ['min_length' => 10, 'max_length' => 5]];
        yield ['text', ['max_length' => 192]];
        yield ['text', ['min_length' => '3']];
        yield ['text', ['placeholder' => "Invalid\nplaceholder"]];
        yield ['checkbox', ['placeholder' => 'Unsupported']];
        yield ['number', ['min_value' => 8, 'max_value' => 2]];
        yield ['number', ['min_value' => 1.5]];
        yield ['date', ['earliest_date' => '2027-02-29']];
        yield ['date', ['earliest_date' => '2028-01-01', 'latest_date' => '2027-01-01']];
        yield ['text', ['pattern' => '(untrusted regex)']];
    }

    #[DataProvider('invalidSettings')]
    public function testRejectsInvalidOrInapplicableSettings(string $type, array $settings): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ApplicationFieldSettings($type, $settings);
    }

    public function testLayoutMigrationIsAdditiveAcrossAllFiveDialects(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/20261116000008_AddSisApplicationFormLayouts.php';
        foreach (
            ['mysql' => 'MySQL', 'pgsql' => 'PostgreSQL', 'sqlite' => 'SQLite',
            'sqlsrv' => 'SQLServer', 'oci' => 'Oracle'] as $driver => $dialect
        ) {
            $pdo = $this->createStub(PDO::class);
            $pdo->method('getAttribute')->willReturn($driver);
            $connection = $this->createStub(Connection::class);
            $connection->method(PropertyHook::get('pdo'))->willReturn($pdo);
            $connection->method('quoteIdentifier')->willReturnCallback(
                static fn(string $name): string => '"' . $name . '"'
            );
            $class = 'Qubus\\Expressive\\Schema\\Compiler\\' . $dialect;
            $compiler = new $class($connection);
            $sql = [];
            $schema = $this->createMock(Schema::class);
            $schema->expects(self::exactly($driver === 'mysql' ? 2 : 1))->method('alter')->willReturnCallback(
                static function (string $name, callable $define) use ($compiler, &$sql): void {
                    $table = new AlterTable($name);
                    $define($table);
                    array_push($sql, ...$compiler->alter($table));
                }
            );
            $connection->method('getSchema')->willReturn($schema);
            $migration = new \AddSisApplicationFormLayouts('layout');
            $migration->setObjectMap(new ArrayObject([
                'phpmig.adapter' => new DbalMigrationAdapter($connection, 'migration'),
            ]));
            $migration->up();
            $text = json_encode($sql, JSON_THROW_ON_ERROR);
            self::assertStringContainsString('layout_json', $text, $dialect);
            if ($driver === 'mysql') {
                self::assertStringContainsString('MEDIUMTEXT', $text);
                self::assertStringContainsString('encrypted_answers', $text);
            }
            self::assertDoesNotMatchRegularExpression('/DELETE|UPDATE |DROP /i', $text, $dialect);
        }
    }

    public function testAddingLayoutStoragePreservesPublishedDefinitionsAndTheirExistingReferences(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/20261116000008_AddSisApplicationFormLayouts.php';
        $connection = new \Qubus\Expressive\Connection\Pdo\Sqlite([
            'driver' => 'pdo_sqlite', 'dsn' => 'sqlite::memory:',
        ]);
        $pdo = $connection->pdo;
        $pdo->exec('CREATE TABLE sis_application_forms (id VARCHAR(26) PRIMARY KEY, fields_json TEXT, version INT)');
        $pdo->exec('CREATE TABLE sis_form_submissions (id VARCHAR(26) PRIMARY KEY, '
            . 'form_id VARCHAR(26) REFERENCES sis_application_forms(id), encrypted_answers TEXT)');
        $pdo->exec("INSERT INTO sis_application_forms VALUES ('form', '[fictional definition bytes]', 5)");
        $pdo->exec("INSERT INTO sis_form_submissions VALUES ('receipt', 'form', 'fictional ciphertext')");
        $before = $pdo->query('SELECT * FROM sis_application_forms')->fetchAll(PDO::FETCH_ASSOC);
        $receipts = $pdo->query('SELECT * FROM sis_form_submissions')->fetchAll(PDO::FETCH_ASSOC);
        $migration = new \AddSisApplicationFormLayouts('layout');
        $migration->setObjectMap(new ArrayObject([
            'phpmig.adapter' => new DbalMigrationAdapter($connection, 'migration'),
        ]));
        $migration->up();
        self::assertSame($before, $pdo->query('SELECT id, fields_json, version FROM sis_application_forms')
            ->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame($receipts, $pdo->query('SELECT * FROM sis_form_submissions')->fetchAll(PDO::FETCH_ASSOC));
        self::assertNull($pdo->query('SELECT layout_json FROM sis_application_forms')->fetchColumn());
        self::assertSame([], $pdo->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_ASSOC));
    }
}
