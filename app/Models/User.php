<?php
// app/Models/User.php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

/**
 * Usuários não pertencem a um lar (podem estar em vários via household_members), por isso sem escopo.
 */
final class User extends Model
{
    protected string $table = 'users';
    protected bool $householdScoped = false;
    protected bool $softDeletes = true;
    protected array $fillable = [
        'name', 'email', 'email_verified_at', 'password_hash', 'totp_secret', 'totp_enabled_at', 'totp_recovery_codes',
        'color', 'timezone', 'locale', 'document', 'adult_confirmed_at', 'status', 'session_idle_minutes',
        'last_login_at', 'last_login_ip', 'deleted_at',
    ];
    protected array $encrypted = ['totp_secret', 'document'];
    protected array $json = ['totp_recovery_codes'];

    /** @return array<string,mixed>|null */
    public function findByEmail(string $email): ?array
    {
        $row = $this->query()->where('email', mb_strtolower(trim($email)))->first();
        return $row === null ? null : $this->castRow($row);
    }

    /** Dados públicos de um usuário para exibição a outros membros (sem e-mail nem campos sensíveis). */
    /** @return array<string,mixed>|null */
    public static function publicProfile(int $id): ?array
    {
        return Database::selectOne('SELECT id, name, color, status FROM users WHERE id = ?', [$id]);
    }
}
