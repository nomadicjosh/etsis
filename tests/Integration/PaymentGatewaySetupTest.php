<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Access\Enum\GrantKind;
use Domain\Payments\Service\GatewayConfiguration;
use Domain\Payments\Service\GatewaySetup;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
use Tests\Support\PaymentGatewayFixture;

final class PaymentGatewaySetupTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/PaymentGatewayFixture.php';
    }

    public function testReviewNeverReturnsCredentialsAndConfirmationRetriesRetainOriginalReceipt(): void
    {
        $f = new PaymentGatewayFixture(false);
        $before = $f->sis->sql->rows('sis_history_events');
        $r = $f->setup->review('connection', $f->input, 'Approve fictional provider connection.');
        self::assertStringNotContainsString('sk_test_', json_encode($r));
        self::assertStringNotContainsString('whsec_', json_encode($r));
        self::assertCount(0, $f->setup->directory('connections')['items']);
        $stored = $f->sis->sql->one('sis_payment_reviews', ['id' => $r['review_id']]);
        self::assertStringNotContainsString('sk_test_', $stored['sealed_data']);
        $saved = $f->setup->confirm(new EntityId($r['review_id']), $r['review_token']);
        $f->sis->clock->time = $f->sis->clock->time->modify('+21 minutes');
        self::assertSame($saved, $f->setup->confirm(new EntityId($r['review_id']), $r['review_token']));
        self::assertNull($f->sis->sql->one('sis_payment_reviews', ['id' => $r['review_id']])['sealed_data']);
        self::assertArrayNotHasKey('secret', $f->setup->show(new EntityId($saved['id'])));
        self::assertSame($before, $f->sis->sql->rows('sis_history_events'));
    }

    public function testCurrentAuthorityStillControlsAnAcceptedRetry(): void
    {
        $f = new PaymentGatewayFixture(false);
        $r = $f->setup->review('connection', $f->input, 'Approve fictional connection.');
        $f->setup->confirm(new EntityId($r['review_id']), $r['review_token']);
        $p = $f->sis->sql->one('sis_access_permissions', ['name' => GatewaySetup::MANAGE]);
        $f->roles->assign(GrantKind::UserPermission, $f->sis->access->actor, new EntityId($p['id']), false);
        $this->expectException(AccessDeniedException::class);
        $f->setup->confirm(new EntityId($r['review_id']), $r['review_token']);
    }

    public function testAnotherActorCannotAdoptAReview(): void
    {
        $f = new PaymentGatewayFixture(false);
        $r = $f->setup->review('connection', $f->input, 'Approve fictional connection.');
        $f->sis->access->actor = $f->sis->user();
        $this->expectException(AccessDeniedException::class);
        $f->setup->confirm(new EntityId($r['review_id']), $r['review_token']);
    }

    public function testExpiredReviewCannotCreateAConnection(): void
    {
        $f = new PaymentGatewayFixture(false);
        $r = $f->setup->review('connection', $f->input, 'Approve fictional connection.');
        $f->sis->clock->time = $f->sis->clock->time->modify('+20 minutes');
        try {
            $f->setup->confirm(new EntityId($r['review_id']), $r['review_token']);
            self::fail('Expired payment setup accepted.');
        } catch (ConcurrentModificationException) {
            self::assertSame([], $f->setup->directory('connections')['items']);
        }
    }

    public function testConnectionEditsUseExpectedRevisionAndMerchantIdentityCannotChange(): void
    {
        $f = new PaymentGatewayFixture();
        $input = array_replace($f->input, [
            'id' => $f->connection->value, 'version' => 1, 'secret' => '', 'webhook' => ''
        ]);
        $r = $f->setup->review('connection', $input, 'Approve fictional label revision.');
        $f->save('connection', array_replace($input, ['name' => 'Fictional changed label']));
        try {
            $f->setup->confirm(new EntityId($r['review_id']), $r['review_token']);
            self::fail('Stale connection review accepted.');
        } catch (ConcurrentModificationException) {
            self::assertSame(2, $f->setup->show($f->connection)['version']);
        }
        $f->client->merchantId = 'acct_other';
        $this->expectException(\DomainException::class);
        $f->setup->review(
            'connection',
            array_replace($input, ['version' => 2, 'secret' => 'sk_test_other']),
            'Attempt changing a fictional merchant.'
        );
    }

    public function testAssetCurrencyClassificationAndControlAccountAreValidated(): void
    {
        $f = new PaymentGatewayFixture(false);
        $ar = $f->sis->ledgerControl()->book('USD')['definition']['receivable']['id'];
        foreach ([$f->income->value, $ar] as $id) {
            try {
                $f->setup->review(
                    'connection',
                    array_replace($f->input, ['asset_id' => $id]),
                    'Review invalid clearing asset.'
                );
                self::fail('Invalid asset accepted.');
            } catch (\DomainException) {
                self::assertTrue(true);
            }
        }
        $eur = $f->ledger->addAccount('EUR', 'Fictional euro clearing', 'EUR', 'asset');
        $this->expectException(\DomainException::class);
        $f->setup->review(
            'connection',
            array_replace($f->input, ['asset_id' => $eur->value]),
            'Review invalid currency.'
        );
    }

    public function testExistingUnknownAccountsCanBeClassifiedWithoutChangingEarlierFacts(): void
    {
        $f = new PaymentGatewayFixture(false);
        $unknown = $f->ledger->addAccount('UNKNOWN', 'Fictional earlier account', 'USD');
        $before = $f->sis->sql->rows('sis_history_events');
        $f->save('classification', ['id' => $unknown->value, 'kind' => 'asset']);
        self::assertSame('asset', $f->sis->sql->one('sis_ledger_accounts', ['id' => $unknown->value])['kind']);
        self::assertSame($before, $f->sis->sql->rows('sis_history_events'));
        $this->expectException(\DomainException::class);
        $f->save('classification', ['id' => $unknown->value, 'kind' => 'expense']);
    }

    public function testRoutesArePurposeAndCurrencySpecificAndCanBeRemoved(): void
    {
        $f = new PaymentGatewayFixture();
        self::assertTrue($f->gateway->enabled('account', 'USD'));
        self::assertFalse($f->gateway->enabled('account', 'EUR'));
        self::assertTrue($f->gateway->enabled('application', 'USD'));
        $f->save('route', ['purpose' => 'application', 'currency' => 'USD', 'connection_id' => null, 'version' => 1]);
        self::assertFalse($f->gateway->enabled('application', 'USD'));
        self::assertTrue($f->gateway->enabled('transcript', 'USD'));
        $row = $f->sis->sql->one('sis_payment_routes', [
            'id' => GatewayConfiguration::routeId('account', 'USD')->value
        ]);
        $f->sis->sql->update('sis_payment_routes', ['currency' => 'EUR'], ['id' => $row['id']]);
        self::assertFalse($f->gateway->enabled('account', 'USD'));
    }

    public function testProductionCannotEnableTestPayments(): void
    {
        $f = new PaymentGatewayFixture(false);
        $f->policy->test = false;
        $this->expectException(\DomainException::class);
        $f->save('connection', $f->input);
    }

    public function testAuditFailureRollsBackSetupAndReceipt(): void
    {
        $audit = $this->createStub(\Domain\Audit\Repository\AuditTrail::class);
        $audit->method('record')->willThrowException(new \RuntimeException('Fictional required audit failure.'));
        $f = new PaymentGatewayFixture(false, $audit);
        $r = $f->setup->review('connection', $f->input, 'Approve fictional connection.');
        try {
            $f->setup->confirm(new EntityId($r['review_id']), $r['review_token']);
            self::fail('Missing audit accepted.');
        } catch (\RuntimeException) {
            self::assertSame([], $f->setup->directory('connections')['items']);
            self::assertNull($f->sis->sql->one('sis_payment_reviews', ['id' => $r['review_id']])['sealed_result']);
        }
    }
    public function testExpiredProposalCleanupIsBoundedAndRetainsAcceptedReceipts(): void
    {
        $f = new PaymentGatewayFixture(false);
        $one = $f->setup->review('connection', $f->input, 'Review fictional connection one.');
        $two = $f->setup->review('connection', $f->input, 'Review fictional connection two.');
        $accepted = $f->setup->review('connection', $f->input, 'Review fictional connection three.');
        $saved = $f->setup->confirm(new EntityId($accepted['review_id']), $accepted['review_token']);
        $f->sis->clock->time = $f->sis->clock->time->modify('+20 minutes');
        $retention = new \Domain\Payments\Service\GatewayReviewRetention(
            $f->configuration->records,
            $f->sis->transactions,
            $f->sis->clock
        );
        self::assertSame(1, $retention->run(1));
        self::assertSame(1, $retention->run(1));
        self::assertSame(0, $retention->run());
        self::assertSame($saved, $f->setup->confirm(new EntityId($accepted['review_id']), $accepted['review_token']));
        foreach ([$one, $two] as $review) {
            self::assertNull($f->sis->sql->one('sis_payment_reviews', ['id' => $review['review_id']])['sealed_data']);
        }
    }

    public function testCreateAssetUsesTheReviewedIdentifierAndNoBusinessEvents(): void
    {
        $f = new PaymentGatewayFixture(false);
        $events = $f->sis->sql->rows('sis_history_events');
        $r = $f->setup->review(
            'asset',
            ['code' => 'NEW.CLEAR', 'title' => 'Fictional new asset', 'currency' => 'USD'],
            'Approve fictional processor clearing asset.'
        );
        $saved = $f->setup->confirm(new EntityId($r['review_id']), $r['review_token']);
        self::assertSame($r['preview']['id'], $saved['id']);
        self::assertSame('asset', $f->sis->sql->one('sis_ledger_accounts', ['id' => $saved['id']])['kind']);
        self::assertSame($events, $f->sis->sql->rows('sis_history_events'));
    }

    public function testSelectedConnectionChangingAfterRouteReviewRequiresAnotherReview(): void
    {
        $f = new PaymentGatewayFixture();
        $r = $f->setup->review('route', ['purpose' => 'account', 'currency' => 'USD', 'version' => 1,
            'connection_id' => $f->connection->value], 'Approve fictional route selection.');
        self::assertSame('acct_fictional', $r['preview']['selected_connection']['merchant']);
        $f->save('connection', array_replace($f->input, ['id' => $f->connection->value, 'version' => 1,
            'secret' => '', 'webhook' => '', 'asset_id' => $f->other->value]));
        $this->expectException(ConcurrentModificationException::class);
        $f->setup->confirm(new EntityId($r['review_id']), $r['review_token']);
    }

    public function testFinalAuthorityCheckRollsBackAnAuditHandlerPermissionChange(): void
    {
        $f = null;
        $audit = $this->createStub(\Domain\Audit\Repository\AuditTrail::class);
        $audit->method('record')->willReturnCallback(static function () use (&$f): void {
            $p = $f->sis->sql->one('sis_access_permissions', ['name' => GatewaySetup::MANAGE]);
            $f->roles->assign(GrantKind::UserPermission, $f->sis->access->actor, new EntityId($p['id']), false);
        });
        $f = new PaymentGatewayFixture(false, $audit);
        $r = $f->setup->review('connection', $f->input, 'Approve fictional connection.');
        try {
            $f->setup->confirm(new EntityId($r['review_id']), $r['review_token']);
            self::fail('Final authority drift accepted.');
        } catch (AccessDeniedException) {
            self::assertSame([], $f->setup->directory('connections')['items']);
            self::assertTrue($f->roles->allows($f->sis->access->actor->value, GatewaySetup::MANAGE));
        }
    }
}
