<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth;
use App\Database;
use PDO;
use RuntimeException;

final class InviteService
{
    public function createInvite(int $createdByUserId): array
    {
        if ($createdByUserId <= 0) {
            throw new RuntimeException('Usuario no válido para crear invitaciones.');
        }

        $token = bin2hex(random_bytes(24));
        $createdAt = date('Y-m-d H:i:s');

        $statement = Database::connection()->prepare(
            'INSERT INTO user_invites (token, created_by, accepted_user_id, created_at, accepted_at)
             VALUES (:token, :created_by, :accepted_user_id, :created_at, :accepted_at)'
        );
        $statement->execute([
            'token' => $token,
            'created_by' => $createdByUserId,
            'accepted_user_id' => null,
            'created_at' => $createdAt,
            'accepted_at' => null,
        ]);

        return [
            'token' => $token,
            'url' => app_full_url('invite.php?token=' . urlencode($token)),
            'created_at' => $createdAt,
        ];
    }

    public function pendingInvites(): array
    {
        $statement = Database::connection()->query(
            'SELECT ui.id, ui.token, ui.created_at, u.username AS created_by_username
             FROM user_invites ui
             INNER JOIN users u ON u.id = ui.created_by
             WHERE ui.accepted_at IS NULL
             ORDER BY ui.created_at DESC'
        );
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn (array $invite): array => [
            'id' => (int) $invite['id'],
            'token' => $invite['token'],
            'created_at' => $invite['created_at'],
            'created_by_username' => $invite['created_by_username'],
            'url' => app_full_url('invite.php?token=' . urlencode((string) $invite['token'])),
        ], $rows);
    }

    public function users(): array
    {
        $statement = Database::connection()->query(
            'SELECT id, username, is_admin, created_at
             FROM users
             ORDER BY is_admin DESC, username ASC'
        );

        return array_map(static fn (array $user): array => [
            'id' => (int) $user['id'],
            'username' => $user['username'],
            'is_admin' => !empty($user['is_admin']),
            'created_at' => $user['created_at'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findPendingInvite(string $token): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT ui.id, ui.token, ui.created_at, u.username AS created_by_username
             FROM user_invites ui
             INNER JOIN users u ON u.id = ui.created_by
             WHERE ui.token = :token
               AND ui.accepted_at IS NULL
             LIMIT 1'
        );
        $statement->execute(['token' => $token]);

        return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function acceptInvite(string $token, string $username, string $password): void
    {
        $invite = $this->findPendingInvite($token);
        if ($invite === null) {
            throw new RuntimeException('La invitación no es válida o ya se ha usado.');
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            $userId = Auth::createInvitedUser($username, $password);

            $statement = $pdo->prepare(
                'UPDATE user_invites
                 SET accepted_user_id = :accepted_user_id, accepted_at = :accepted_at
                 WHERE id = :id AND accepted_at IS NULL'
            );
            $statement->execute([
                'accepted_user_id' => $userId,
                'accepted_at' => date('Y-m-d H:i:s'),
                'id' => (int) $invite['id'],
            ]);

            if ($statement->rowCount() === 0) {
                throw new RuntimeException('La invitación ya no está disponible.');
            }

            $pdo->commit();
        } catch (\Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }
}
