<?php

declare(strict_types=1);

namespace Thallo\Importers;

use Glueful\Auth\PasswordHasher;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Aegis\AegisPermissionProvider;
use Glueful\Extensions\ImportExport\Support\ImportContext;
use Glueful\Extensions\ImportExport\Support\ImportOptions;
use Glueful\Extensions\Users\Repositories\UserRepository;
use Glueful\Helpers\Utils;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Importers\Concerns\RequiresImportersCapability;

/**
 * Bulk-creates users from a CSV — one user per row.
 *
 * Maps columns to user/profile/role fields via the `options.mapping` bag (field => column). Account
 * fields (`username`, `email`, `password`, `status`) go through {@see UserRepository::create()} (the
 * password is hashed here; a missing one is randomly generated so the account exists but the user
 * must reset), profile fields (`first_name`, `last_name`) through `updateProfile()`, and `roles`
 * (a comma-separated list of slugs) are assigned via Aegis. `username` + `email` are required and
 * checked for uniqueness — against the database AND within the file (both rows of an intra-file
 * duplicate are rejected, so dry-run and commit report identically); `dry_run` validates without
 * writing. Create-only.
 *
 * DELIBERATE: imported accounts are stamped email-verified (`email_verified_at` = import time).
 * This is bulk *provisioning* by an admin who vouches for the addresses — without the stamp every
 * imported user would be walked through a verification flow for an email nobody sent. Don't import
 * unvetted address lists.
 */
final class CsvUserImporter extends AbstractCsvImporter
{
    use RequiresImportersCapability;

    /** Mappable fields (account + profile + roles). */
    public const FIELDS = ['username', 'email', 'password', 'status', 'first_name', 'last_name', 'roles'];
    private const REQUIRED = ['username', 'email'];
    /** The status vocabulary the users pack + admin SPA use. */
    private const STATUSES = ['active', 'inactive'];

    public function __construct(
        ApplicationContext $context,
        Connection $db,
        private readonly UserRepository $users,
        private readonly AegisPermissionProvider $aegis,
        private readonly CapabilityRegistry $capabilities,
    ) {
        parent::__construct($context, $db);
    }

    public function key(): string
    {
        return 'csv.users';
    }

    public function label(): string
    {
        return 'Users (CSV)';
    }

    protected function assertEnabled(): void
    {
        $this->assertImportersEnabled($this->capabilities);
    }

    protected function validatePlan(array $header, ImportOptions $options): void
    {
        $this->assertEnabled();
        $mapping = $this->mappingOption($options->options);
        if ($mapping === []) {
            throw new \InvalidArgumentException('A column mapping is required.');
        }
        foreach (self::REQUIRED as $field) {
            if (!isset($mapping[$field])) {
                throw new \InvalidArgumentException(sprintf('The "%s" field must be mapped to a column.', $field));
            }
        }
        foreach ($mapping as $field => $column) {
            if (in_array($field, self::FIELDS, true) && !in_array($column, $header, true)) {
                throw new \InvalidArgumentException(
                    sprintf('CSV has no column "%s" (mapped to "%s").', $column, $field),
                );
            }
        }
    }

    protected function planMetadata(ImportOptions $options): array
    {
        return ['format' => 'csv', 'target' => 'users'];
    }

    protected function prepare(ImportContext $context): array
    {
        $mapping = $this->mappingOption($context->options);

        // Intra-file duplicates: the per-row DB uniqueness checks can't see them, so two rows
        // sharing an email both passed dry-run and then one failed on commit. Collect the
        // duplicated values across the WHOLE file; every row carrying one is rejected (which of
        // the conflicting rows is right is ambiguous), keeping dry-run and commit identical.
        $emails = [];
        $usernames = [];
        foreach ($this->recordsForJob($context->jobUuid) as $row) {
            $email = strtolower($this->value($row, $mapping, 'email'));
            $username = $this->value($row, $mapping, 'username');
            if ($email !== '') {
                $emails[$email] = ($emails[$email] ?? 0) + 1;
            }
            if ($username !== '') {
                $usernames[$username] = ($usernames[$username] ?? 0) + 1;
            }
        }

        return [
            'mapping' => $mapping,
            'dupEmails' => array_filter($emails, static fn(int $n): bool => $n > 1),
            'dupUsernames' => array_filter($usernames, static fn(int $n): bool => $n > 1),
        ];
    }

    protected function importRow(array $row, array $prepared, ImportContext $context): void
    {
        $mapping = $prepared['mapping'];
        $username = $this->value($row, $mapping, 'username');
        $email = $this->value($row, $mapping, 'email');

        if ($username === '' || $email === '') {
            throw new \InvalidArgumentException('Both username and email are required.');
        }
        if (!Utils::isValidEmail($email)) {
            throw new \InvalidArgumentException(sprintf('Invalid email "%s".', $email));
        }
        if (isset($prepared['dupEmails'][strtolower($email)])) {
            throw new \InvalidArgumentException(sprintf('Email "%s" appears more than once in the file.', $email));
        }
        if (isset($prepared['dupUsernames'][$username])) {
            throw new \InvalidArgumentException(
                sprintf('Username "%s" appears more than once in the file.', $username),
            );
        }
        $status = $this->value($row, $mapping, 'status');
        if ($status !== '' && !in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid status "%s"; valid values: %s.',
                $status,
                implode(', ', self::STATUSES),
            ));
        }
        if ($this->users->emailExists($email)) {
            throw new \InvalidArgumentException(sprintf('A user with email "%s" already exists.', $email));
        }
        if ($this->users->usernameExists($username)) {
            throw new \InvalidArgumentException(sprintf('A user with username "%s" already exists.', $username));
        }

        if ($context->mode !== 'commit') {
            return;
        }

        // One CSV row is one logical unit: the account, its profile, and its role assignments must
        // all land or none do. Without the transaction a failure after create() (a profile/role
        // write error, or an unknown role slug) left an orphaned `active` account that the uniqueness
        // pre-checks above then made permanently un-retryable. A rollback lets the row be re-imported
        // cleanly.
        $this->db->transaction(function () use ($row, $mapping, $username, $email, $status): void {
            $password = $this->value($row, $mapping, 'password');
            $uuid = $this->users->create([
                'username' => $username,
                'email' => $email,
                // create() does not hash; a missing password becomes a random one (user must reset).
                'password' => (new PasswordHasher())->hash(
                    $password !== '' ? $password : Utils::generateSecurePassword()
                ),
                'status' => $status !== '' ? $status : 'active',
                'email_verified_at' => date('Y-m-d H:i:s'),
            ]);

            $profile = array_filter([
                'first_name' => $this->value($row, $mapping, 'first_name'),
                'last_name' => $this->value($row, $mapping, 'last_name'),
            ], static fn(string $v): bool => $v !== '');
            if ($profile !== []) {
                $this->users->updateProfile($uuid, $profile);
            }

            foreach ($this->roles($row, $mapping) as $slug) {
                // assignRole returns false on an unknown/invalid slug; previously that was silently
                // swallowed. Surface it as a row error so a typo'd role is reported (and, inside the
                // transaction, rolls the account back) instead of importing a user missing its role.
                if (!$this->aegis->assignRole($uuid, $slug)) {
                    throw new \RuntimeException(sprintf('Could not assign role "%s" (unknown or invalid).', $slug));
                }
            }
        });
    }

    protected function errorCode(): string
    {
        return 'csv_user_import_failed';
    }

    /**
     * @param array<string,string> $row
     * @param array<string,string> $mapping
     */
    private function value(array $row, array $mapping, string $field): string
    {
        $column = $mapping[$field] ?? null;
        return $column !== null ? trim((string) ($row[$column] ?? '')) : '';
    }

    /**
     * @param array<string,string> $row
     * @param array<string,string> $mapping
     * @return list<string>
     */
    private function roles(array $row, array $mapping): array
    {
        $raw = $this->value($row, $mapping, 'roles');
        if ($raw === '') {
            return [];
        }
        return array_values(array_filter(
            array_map(static fn(string $s): string => trim($s), explode(',', $raw)),
            static fn(string $s): bool => $s !== '',
        ));
    }
}
