<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Portal\Repository\CampusRepository;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlCampusRepository implements CampusRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function terms(): array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch('SELECT ' . $q('id') . ', ' . $q('code') . ', ' . $q('starts_on')
            . ', ' . $q('ends_on') . ' FROM ' . $q('sis_terms') . ' ORDER BY ' . $q('starts_on') . ' DESC', [], 100);
    }

    public function programs(string $search = '', ?EntityId $after = null): array
    {
        $q = $this->sql->quote(...);
        $where = [];
        $params = [];
        if ($search !== '') {
            $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)) . '%';
            $where[] = '(LOWER(p.' . $q('code') . ") LIKE ? ESCAPE '!' OR LOWER(p." . $q('title')
            . ") LIKE ? ESCAPE '!' OR p." . $q('id') . ' = ?)';
            array_push($params, $pattern, $pattern, $search);
        }
        if ($after !== null) {
            $where[] = 'p.' . $q('id') . ' > ?';
            $params[] = $after->value;
        }
        return $this->sql->fetch('SELECT p.' . $q('id') . ', p.' . $q('code') . ', p.' . $q('title')
            . ', f.' . $q('form_id') . ' FROM ' . $q('sis_programs') . ' p LEFT JOIN '
            . $q('sis_program_forms') . ' f ON f.' . $q('program_id') . ' = p.' . $q('id')
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY p.' . $q('id'), $params, 100);
    }

    public function sections(?EntityId $term, string $search, ?EntityId $after): array
    {
        $q = $this->sql->quote(...);
        $where = ['s.' . $q('active') . ' = 1', 'c.' . $q('active') . ' = 1'];
        $params = [];
        if ($term !== null) {
            $where[] = 's.' . $q('term_id') . ' = ?';
            $params[] = $term->value;
        }
        if ($after !== null) {
            $where[] = 's.' . $q('id') . ' < ?';
            $params[] = $after->value;
        }
        if ($search !== '') {
            $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)) . '%';
            $where[] = '(LOWER(c.' . $q('code') . ") LIKE ? ESCAPE '!' OR LOWER(c." . $q('title')
            . ") LIKE ? ESCAPE '!' OR LOWER(s." . $q('code') . ") LIKE ? ESCAPE '!')";
            array_push($params, $pattern, $pattern, $pattern);
        }
        $rows = $this->sql->fetch('SELECT s.' . $q('id') . ', s.' . $q('code') . ' AS ' . $q('section_code')
            . ', s.' . $q('term_id') . ', s.' . $q('capacity') . ', s.' . $q('web_registration')
            . ', s.' . $q('fee_minor') . ', s.' . $q('currency') . ', c.' . $q('code') . ' AS '
            . $q('course_code') . ', c.' . $q('title') . ', c.' . $q('credits_milli') . ', t.' . $q('code')
            . ' AS ' . $q('term_code') . ', (SELECT COUNT(*) FROM ' . $q('sis_enrollments') . ' e WHERE e.'
            . $q('section_id') . ' = s.' . $q('id') . ' AND e.' . $q('status') . " IN ('A', 'N')) AS "
            . $q('enrolled') . ' FROM ' . $q('sis_sections') . ' s JOIN ' . $q('sis_courses') . ' c ON c.'
            . $q('id') . ' = s.' . $q('course_id') . ' JOIN ' . $q('sis_terms') . ' t ON t.' . $q('id')
            . ' = s.' . $q('term_id') . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY s.' . $q('id')
            . ' DESC', $params, 25);
        $more = count($rows) === 25;
        $rows = array_slice($rows, 0, 24);
        return ['items' => $rows, 'next_after' => $more ? $rows[23]['id'] : null];
    }

    public function news(bool $campus, ?EntityId $after = null): array
    {
        $q = $this->sql->quote(...);
        $where = 'p.' . $q('status') . " = 'published' AND p." . $q('kind') . " = 'news'";
        if (!$campus) {
            $where .= ' AND a.' . $q('audience') . " = 'public'";
        }
        $params = [];
        if ($after !== null) {
            $where .= ' AND p.' . $q('id') . ' < ?';
            $params[] = $after->value;
        }
        return $this->sql->fetch('SELECT p.' . $q('id') . ', p.' . $q('code') . ', p.' . $q('title')
            . ', p.' . $q('content') . ', a.' . $q('published_at') . ' FROM ' . $q('sis_portal_posts')
            . ' p LEFT JOIN ' . $q('sis_portal_audiences') . ' a ON a.' . $q('post_id') . ' = p.' . $q('id')
            . ' WHERE ' . $where . ' ORDER BY p.' . $q('id') . ' DESC', $params, 24);
    }

    public function article(string $code, bool $campus): array
    {
        $post = $this->sql->one('sis_portal_posts', ['code' => $code, 'status' => 'published', 'kind' => 'news']);
        if (!$campus && $this->audience(new EntityId($post['id'])) !== 'public') {
            throw new RecordNotFoundException('Article not found.');
        }
        return ['id' => $post['id'], 'title' => $post['title'], 'content' => $post['content'], 'code' => $post['code']];
    }

    public function audience(EntityId $post): string
    {
        return $this->sql->rows('sis_portal_audiences', ['post_id' => $post->value], 1)[0]['audience'] ?? 'campus';
    }

    public function setAudience(EntityId $post, string $audience): void
    {
        $this->sql->lock('sis_portal_posts', $post->value, 'id');
        $existing = $this->sql->rows('sis_portal_audiences', ['post_id' => $post->value], 1);
        if ($existing === []) {
            $this->sql->insert('sis_portal_audiences', ['id' => EntityId::generate()->value,
                'post_id' => $post->value, 'audience' => $audience,
                'published_at' => null]);
        } else {
            $this->sql->update('sis_portal_audiences', ['audience' => $audience], ['post_id' => $post->value]);
        }
    }

    public function personForUser(EntityId $user): EntityId
    {
        return new EntityId($this->sql->one('sis_persons', ['owner_user_id' => $user->value])['id']);
    }

    public function lockPerson(EntityId $person): void
    {
        $this->sql->lock('sis_persons', $person->value, 'id');
    }

    public function requireOwnedAccount(EntityId $user, EntityId $account): void
    {
        $record = $this->sql->one('sis_student_accounts', ['id' => $account->value]);
        $this->ownedStudent($user, new EntityId($record['student_id']));
    }

    public function overview(EntityId $user): array
    {
        $person = $this->personForUser($user);
        $profile = $this->sql->one('sis_persons', ['id' => $person->value]);
        $identity = $this->sql->one('sis_person_identifiers', ['person_id' => $person->value]);
        $students = $this->sql->rows('sis_students', ['person_id' => $person->value], 20);
        $accounts = [];
        foreach ($students as $student) {
            array_push($accounts, ...$this->sql->rows('sis_student_accounts', ['student_id' => $student['id']], 50));
        }
        return ['person' => ['id' => $person->value, 'given_name' => $profile['given_name'],
            'family_name' => $profile['family_name'], 'institutional_id' => $identity['institutional_id']],
            'students' => $students, 'accounts' => $accounts,
            'applications' => $this->sql->rows('sis_applications', ['person_id' => $person->value], 50),
            'restrictions' => $this->sql->rows('sis_restrictions', ['person_id' => $person->value], 50)];
    }

    public function ownedStudent(EntityId $user, EntityId $student): array
    {
        $record = $this->sql->one('sis_students', ['id' => $student->value]);
        if ($record['person_id'] !== $this->personForUser($user)->value) {
            throw new AccessDeniedException('Access denied.');
        }
        return $record;
    }

    public function enrollments(EntityId $student): array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch('SELECT e.' . $q('id') . ', e.' . $q('section_id') . ', e.' . $q('status')
            . ', e.' . $q('term_id') . ', s.' . $q('code') . ' AS ' . $q('section_code') . ', c.' . $q('code')
            . ' AS ' . $q('course_code') . ', c.' . $q('title') . ', c.' . $q('credits_milli') . ' FROM '
            . $q('sis_enrollments') . ' e JOIN ' . $q('sis_sections') . ' s ON s.' . $q('id') . ' = e.'
            . $q('section_id') . ' JOIN ' . $q('sis_courses') . ' c ON c.' . $q('id') . ' = s.' . $q('course_id')
            . ' WHERE e.' . $q('student_id') . ' = ? ORDER BY e.' . $q('id') . ' DESC', [$student->value], 100);
    }

    public function contact(EntityId $person): ?array
    {
        return $this->sql->rows('sis_portal_contacts', ['person_id' => $person->value], 1)[0] ?? null;
    }

    public function saveContact(EntityId $person, string $encrypted, int $version): void
    {
        $this->sql->lock('sis_persons', $person->value, 'id');
        $existing = $this->contact($person);
        if ($existing === null && $version === 0) {
            $this->sql->insert('sis_portal_contacts', ['id' => EntityId::generate()->value,
                'person_id' => $person->value, 'encrypted_contact' => $encrypted, 'version' => 1]);
        } elseif (
            $existing === null || $this->sql->update(
                'sis_portal_contacts',
                ['encrypted_contact' => $encrypted, 'version' => $version + 1],
                ['person_id' => $person->value, 'version' => $version]
            ) !== 1
        ) {
            throw new ConcurrentModificationException('Reload your current contact information.');
        }
    }

    public function addTranscriptRequest(EntityId $id, array $data): void
    {
        $this->sql->insert('sis_transcript_requests', ['id' => $id->value] + $data);
    }

    public function transcriptRequests(?EntityId $owner, ?EntityId $after = null): array
    {
        $q = $this->sql->quote(...);
        $where = [];
        $params = [];
        if ($owner !== null) {
            $where[] = $q('owner_user_id') . ' = ?';
            $params[] = $owner->value;
        }
        if ($after !== null) {
            $where[] = $q('id') . ' < ?';
            $params[] = $after->value;
        }
        return $this->sql->fetch('SELECT ' . $q('id') . ', ' . $q('student_id') . ', ' . $q('delivery')
            . ', ' . $q('status') . ', ' . $q('requested_at') . ', ' . $q('version') . ' FROM '
            . $q('sis_transcript_requests') . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY ' . $q('id') . ' DESC', $params, 50);
    }

    public function transcriptRequest(EntityId $id): array
    {
        return $this->sql->one('sis_transcript_requests', ['id' => $id->value]);
    }

    public function changeTranscriptStatus(EntityId $id, string $status, int $version): void
    {
        if (
            $this->sql->update(
                'sis_transcript_requests',
                ['status' => $status, 'version' => $version + 1],
                ['id' => $id->value, 'version' => $version]
            ) !== 1
        ) {
            throw new ConcurrentModificationException('The transcript request changed.');
        }
    }
}
