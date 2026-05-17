<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

/**
 * Generate complete auth system scaffolding.
 *
 * Creates migrations, AuthController, User model, UserService,
 * RefreshTokenService, and auth routes for register/login/me/
 * refresh/logout/verifyEmail/forgotPassword/resetPassword.
 *
 * @package Siro\Core\Commands
 */
final class MakeAuthCommand implements \Siro\Core\Commands\CommandInterface {
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $this->generateMigrations();
        $this->generateAuthController();
        $this->updateUserModel();
        $this->generateServices();
        $this->updateRoutes();

        $this->write('');
        $this->write('Auth system generated successfully!');
        $this->write('');
        $this->write('Next steps:');
        $this->write('  1. Run: php siro migrate');
        $this->write('  2. Run: php siro db:seed (optional, creates admin user)');
        $this->write('');

        return 0;
    }

    private function generateMigrations(): void
    {
        $migrationDir = $this->basePath . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';
        if (!is_dir($migrationDir)) {
            mkdir($migrationDir, 0775, true);
        }

        $refreshTokenMigration = $migrationDir . DIRECTORY_SEPARATOR . date('YmdHis', time()) . '_create_refresh_tokens_table.php';
        $this->write('Generated: database/migrations/..._create_refresh_tokens_table.php');
        file_put_contents($refreshTokenMigration, $this->refreshTokenMigrationTemplate());
        sleep(1);

        $verifyMigration = $migrationDir . DIRECTORY_SEPARATOR . date('YmdHis', time()) . '_add_auth_fields_to_users_table.php';
        $this->write('Generated: database/migrations/..._add_auth_fields_to_users_table.php');
        file_put_contents($verifyMigration, $this->authFieldsMigrationTemplate());
    }

    private function generateAuthController(): void
    {
        $controllerDir = $this->basePath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Controllers';
        if (!is_dir($controllerDir)) {
            mkdir($controllerDir, 0775, true);
        }

        $path = $controllerDir . DIRECTORY_SEPARATOR . 'AuthController.php';
        if (is_file($path)) {
            if (!$this->confirmOverwrite($this->basePath, $path)) {
                $this->write('Skipped: app/Controllers/AuthController.php');
                return;
            }
        }

        file_put_contents($path, $this->controllerTemplate());
        $this->write('Generated: app/Controllers/AuthController.php');
    }

    private function updateUserModel(): void
    {
        $path = $this->basePath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Models' . DIRECTORY_SEPARATOR . 'User.php';
        if (is_file($path)) {
            $this->write('Exists: app/Models/User.php (skipped)');
            return;
        }

        $modelDir = dirname($path);
        if (!is_dir($modelDir)) {
            mkdir($modelDir, 0775, true);
        }

        file_put_contents($path, $this->modelTemplate());
        $this->write('Generated: app/Models/User.php');
    }

    private function generateServices(): void
    {
        $serviceDir = $this->basePath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Services';
        if (!is_dir($serviceDir)) {
            mkdir($serviceDir, 0775, true);
        }

        $userServicePath = $serviceDir . DIRECTORY_SEPARATOR . 'UserService.php';
        if (!is_file($userServicePath)) {
            file_put_contents($userServicePath, $this->userServiceTemplate());
            $this->write('Generated: app/Services/UserService.php');
        } else {
            $this->write('Exists: app/Services/UserService.php (skipped)');
        }

        $refreshPath = $serviceDir . DIRECTORY_SEPARATOR . 'RefreshTokenService.php';
        if (!is_file($refreshPath)) {
            file_put_contents($refreshPath, $this->refreshTokenServiceTemplate());
            $this->write('Generated: app/Services/RefreshTokenService.php');
        } else {
            $this->write('Exists: app/Services/RefreshTokenService.php (skipped)');
        }
    }

    private function updateRoutes(): void
    {
        $path = $this->basePath . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'api.php';
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        if (is_file($path)) {
            $existing = (string) file_get_contents($path);
            if (str_contains($existing, 'AuthController')) {
                $this->write('Exists: routes/api.php (already has auth routes)');
                return;
            }
        }

        file_put_contents($path, $this->routesTemplate());
        $this->write('Updated: routes/api.php');
    }

    private function controllerTemplate(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\User;
use App\Services\RefreshTokenService;
use App\Services\UserService;
use Siro\Core\Request;
use Siro\Core\Response;
use Throwable;

final class AuthController
{
    public function __construct(
        private readonly UserService $userService,
        private readonly RefreshTokenService $refreshTokenService,
    ) {
    }

    public function register(Request $request): Response
    {
        $request->validate([
            'name' => 'required|min:3|max:120',
            'email' => 'required|email|max:255',
            'password' => 'required|min:8|max:255',
        ]);

        $email = strtolower(trim($request->string('email')));

        $existingUser = $this->userService->getByEmail($email);
        if ($existingUser !== null) {
            return Response::error('Validation failed', 422, [
                'email' => ['Email has already been taken'],
            ]);
        }

        try {
            $user = $this->userService->createUser([
                'name' => $request->string('name'),
                'email' => $email,
                'password' => $request->string('password'),
            ]);
        } catch (Throwable) {
            return Response::error('Unable to create account', 500);
        }

        $userId = (int) $user->id;
        $tokens = $this->tokenPair($userId);

        return Response::created([
            'token' => $tokens['token'],
            'refresh_token' => $tokens['refresh_token'],
            'token_type' => 'Bearer',
            'expires_in' => $tokens['ttl'],
            'user' => [
                'id' => $userId,
                'name' => $request->string('name'),
                'email' => $email,
            ],
        ], 'Register successful');
    }

    public function login(Request $request): Response
    {
        $request->validate([
            'email' => 'required|email|max:255',
            'password' => 'required|min:8|max:255',
        ]);

        $email = strtolower(trim($request->string('email')));
        $userData = $this->userService->getByEmail($email);

        if ($userData === null || !isset($userData['password']) || !is_string($userData['password'])) {
            return Response::error('Invalid credentials', 401);
        }

        if ((int) ($userData['status'] ?? 0) !== 1) {
            return Response::error('Account is inactive', 403);
        }

        $lockedUntil = $userData['locked_until'] ?? null;
        if ($lockedUntil !== null && $lockedUntil !== '' && strtotime($lockedUntil) > time()) {
            return Response::error('Account is temporarily locked. Try again later.', 429);
        }

        if (!password_verify($request->string('password'), $userData['password'])) {
            $rawId = $userData['id'] ?? 0;
            $rawAttempts = $userData['login_attempts'] ?? 0;
            $this->userService->incrementLoginAttempts((int) $rawId, (int) $rawAttempts);
            return Response::error('Invalid credentials', 401);
        }

        $rawId = $userData['id'] ?? 0;
        $this->userService->resetLoginAttempts((int) $rawId);

        $tokens = $this->tokenPair((int) $rawId);
        $name = (string) ($userData['name'] ?? '');
        $emailField = (string) ($userData['email'] ?? '');

        return Response::success([
            'token' => $tokens['token'],
            'refresh_token' => $tokens['refresh_token'],
            'token_type' => 'Bearer',
            'expires_in' => $tokens['ttl'],
            'user' => [
                'id' => (int) $rawId,
                'name' => $name,
                'email' => $emailField,
            ],
        ], 'Login successful');
    }

    public function refresh(Request $request): Response
    {
        $request->validate(['refresh_token' => 'required']);

        $tokens = $this->refreshTokenService->verifyAndRotate($request->string('refresh_token'));

        if ($tokens === null) {
            return Response::error('Invalid or expired refresh token', 401);
        }

        return Response::success([
            'token' => $tokens['token'],
            'refresh_token' => $tokens['refresh_token'],
            'token_type' => 'Bearer',
            'expires_in' => $tokens['ttl'],
        ], 'Token refreshed');
    }

    public function me(Request $request): Response
    {
        $user = $request->user();
        if ($user === null) {
            return Response::error('Unauthorized', 401);
        }

        unset($user['claims']);
        return Response::success($user, 'Authenticated user');
    }

    public function logout(Request $request): Response
    {
        $user = $request->user();
        $rawId = $user['id'] ?? 0;
        $userId = (int) $rawId;

        if ($userId <= 0) {
            return Response::error('Unauthorized', 401);
        }

        if (!$this->userService->incrementTokenVersion($userId)) {
            return Response::error('Unable to revoke token', 500);
        }

        return Response::success(null, 'Logout successful. Token revoked.');
    }

    public function verifyEmail(Request $request): Response
    {
        $request->validate(['token' => 'required']);

        $token = $request->string('token');
        $result = $this->userService->verifyEmail($token);

        if (!$result) {
            return Response::error('Invalid verification token', 400);
        }

        return Response::success(null, 'Email verified successfully');
    }

    public function forgotPassword(Request $request): Response
    {
        $request->validate(['email' => 'required|email']);

        $email = strtolower(trim($request->string('email')));
        $this->userService->initiatePasswordReset($email);

        return Response::success(null, 'If the email exists, a reset link has been sent.');
    }

    public function resetPassword(Request $request): Response
    {
        $request->validate([
            'token' => 'required',
            'password' => 'required|min:8|max:255',
        ]);

        $token = $request->string('token');
        $result = $this->userService->resetPassword($token, $request->string('password'));

        if (!$result) {
            return Response::error('Invalid or expired reset token', 400);
        }

        return Response::success(null, 'Password reset successfully');
    }

    /** @return array{token:string,refresh_token:string,ttl:int} */
    private function tokenPair(int $userId): array
    {
        return $this->refreshTokenService->createPair($userId);
    }
}
PHP;
    }

    private function modelTemplate(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property int $status
 * @property int $token_version
 * @property int $login_attempts
 * @property string|null $locked_until
 * @property string|null $email_verified_at
 * @property string|null $verification_token
 * @property string|null $password_reset_token
 * @property string|null $password_reset_expires_at
 * @property string $created_at
 * @property string|null $updated_at
 */
final class User extends Model
{
    protected string $table = 'users';

    /** @var array<int, string> */
    protected array $hidden = ['password'];

    /** @var array<string, string> */
    protected array $casts = [
        'id' => 'int',
        'status' => 'int',
        'token_version' => 'int',
        'login_attempts' => 'int',
        'locked_until' => 'datetime',
        'email_verified_at' => 'datetime',
    ];

    /** @var array<int, string> */
    protected array $fillable = [
        'name',
        'email',
        'password',
        'password_reset_expires_at',
    ];
}
PHP;
    }

    private function userServiceTemplate(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Siro\Core\DB;

final class UserService
{
    public function incrementTokenVersion(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $user = User::find($userId);
        if ($user === null) {
            return false;
        }

        $rawVersion = $user->token_version ?? 0;
        $currentVersion = is_numeric($rawVersion) ? (int) $rawVersion : 0;
        return $user->update(['token_version' => $currentVersion + 1]) > 0;
    }

    public function getTokenVersion(int $userId): int
    {
        $user = User::find($userId);
        if ($user === null) return 1;

        $rawVersion = $user->token_version ?? 0;
        return is_numeric($rawVersion) && (int) $rawVersion > 0 ? (int) $rawVersion : 1;
    }

    /** @return array<string, mixed>|null */
    public function getByEmail(string $email): ?array
    {
        $rows = User::where('email', '=', $email)->limit(1)->get();
        return $rows[0] ?? null;
    }

    /** @param array<string, mixed> $data */
    public function createUser(array $data): User
    {
        $passwordHash = password_hash((string) ($data['password'] ?? ''), PASSWORD_BCRYPT, ['cost' => 12]);
        if ($passwordHash === false) {
            throw new \RuntimeException('Password hashing failed');
        }

        $user = User::create([
            'name' => $data['name'] ?? '',
            'email' => strtolower(trim((string) ($data['email'] ?? ''))),
            'password' => $passwordHash,
            'status' => 1,
            'token_version' => 1,
            'verification_token' => hash('sha256', bin2hex(random_bytes(32))),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $user;
    }

    public function verifyEmail(string $token): bool
    {
        $hashedToken = hash('sha256', $token);
        $rows = User::where('verification_token', '=', $hashedToken)->limit(1)->get();
        $user = isset($rows[0]) ? User::hydrate($rows[0]) : null;

        if ($user === null) {
            return false;
        }

        $user->update([
            'email_verified_at' => date('Y-m-d H:i:s'),
            'verification_token' => null,
        ]);

        return true;
    }

    public function initiatePasswordReset(string $email): void
    {
        $rows = User::where('email', '=', $email)->limit(1)->get();
        $user = isset($rows[0]) ? User::hydrate($rows[0]) : null;

        if ($user === null) {
            return;
        }

        $resetToken = bin2hex(random_bytes(32));
        $hashedToken = hash('sha256', $resetToken);
        $user->update([
            'password_reset_token' => $hashedToken,
            'password_reset_expires_at' => date('Y-m-d H:i:s', time() + 3600),
        ]);
    }

    public function resetPassword(string $token, string $newPassword): bool
    {
        $hashedToken = hash('sha256', $token);
        $rows = User::where('password_reset_token', '=', $hashedToken)->limit(1)->get();
        $user = isset($rows[0]) ? User::hydrate($rows[0]) : null;

        if ($user === null) {
            return false;
        }

        $userData = $user->toArray();
        $expiresAt = (string) ($userData['password_reset_expires_at'] ?? '');

        if ($expiresAt !== '' && strtotime($expiresAt) < time()) {
            return false;
        }

        $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        if ($passwordHash === false) {
            return false;
        }

        $rawVersion = $userData['token_version'] ?? 1;
        $currentVersion = is_numeric($rawVersion) ? (int) $rawVersion : 1;

        $user->update([
            'password' => $passwordHash,
            'password_reset_token' => null,
            'password_reset_expires_at' => null,
            'token_version' => $currentVersion + 1,
        ]);

        return true;
    }

    public function incrementLoginAttempts(int $userId, int $currentAttempts): void
    {
        $newAttempts = $currentAttempts + 1;
        $update = ['login_attempts' => $newAttempts];

        if ($newAttempts >= 5) {
            $update['locked_until'] = date('Y-m-d H:i:s', time() + 900);
        }

        DB::table('users')->where('id', '=', $userId)->update($update);
    }

    public function resetLoginAttempts(int $userId): void
    {
        DB::table('users')->where('id', '=', $userId)->update([
            'login_attempts' => 0,
            'locked_until' => null,
        ]);
    }
}
PHP;
    }

    private function refreshTokenServiceTemplate(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Services;

use Siro\Core\Auth\JWT;
use Siro\Core\DB;
use Siro\Core\Env;

final class RefreshTokenService
{
    public function __construct(
        private readonly UserService $userService,
    ) {
    }

    /** @return array{token: string, refresh_token: string, ttl: int} */
    public function createPair(int $userId): array
    {
        $ttl = max(60, (int) Env::get('JWT_TTL', '3600'));
        $refreshTtl = max(3600, (int) Env::get('JWT_REFRESH_TTL', '604800'));

        $tokenVersion = $this->userService->getTokenVersion($userId);
        $token = JWT::encodeAccess($userId, $tokenVersion, $ttl);
        $jti = bin2hex(random_bytes(16));
        $refreshToken = JWT::encodeRefresh($userId, $tokenVersion, $refreshTtl, $jti);

        DB::table('refresh_tokens')->insert([
            'jti' => $jti,
            'user_id' => $userId,
            'revoked' => 0,
            'expires_at' => date('Y-m-d H:i:s', time() + $refreshTtl),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return [
            'token' => $token,
            'refresh_token' => $refreshToken,
            'ttl' => $ttl,
        ];
    }

    /** @return array{token: string, refresh_token: string, ttl: int}|null */
    public function verifyAndRotate(string $refreshToken): ?array
    {
        try {
            $claims = JWT::decode($refreshToken);
        } catch (\Throwable) {
            return null;
        }

        if (($claims['type'] ?? '') !== JWT::TYPE_REFRESH) return null;

        $rawUserId = $claims['sub'] ?? 0;
        $rawJti = $claims['jti'] ?? '';
        $userId = (int) $rawUserId;
        $jti = (string) $rawJti;

        if ($userId <= 0 || $jti === '') return null;

        $stored = DB::table('refresh_tokens')
            ->where('jti', '=', $jti)
            ->where('revoked', '=', 0)
            ->first();

        if ($stored === null) return null;

        DB::table('refresh_tokens')
            ->where('jti', '=', $jti)
            ->update(['revoked' => 1]);

        return $this->createPair($userId);
    }

    public function revokeAllForUser(int $userId): void
    {
        DB::table('refresh_tokens')
            ->where('user_id', '=', $userId)
            ->update(['revoked' => 1]);
    }
}
PHP;
    }

    private function routesTemplate(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use Siro\Core\Middleware\CorsMiddleware;
use Siro\Core\Middleware\JsonMiddleware;

$app->router->get('/', function (): array {
    return [
        'success' => true,
        'message' => 'Siro API Framework is running',
        'data' => [
            'name' => 'Siro API Framework',
            'version' => \Siro\Core\Console::getVersion(),
            'php' => PHP_VERSION,
        ],
        'meta' => [],
    ];
});

$app->router->get('/health', function (): array {
    return [
        'success' => true,
        'message' => 'OK',
        'data' => [
            'status' => 'alive',
            'time' => date('c'),
        ],
    ];
});

$app->router->get('/health/ready', function (): array {
    $dbOk = false;
    $cacheOk = false;
    try {
        \Siro\Core\Database::connection()->query('SELECT 1');
        $dbOk = true;
    } catch (\Throwable) {
    }
    return [
        'success' => true,
        'message' => 'OK',
        'data' => [
            'status' => $dbOk ? 'ready' : 'degraded',
            'database' => $dbOk ? 'connected' : 'unreachable',
            'time' => date('c'),
        ],
    ];
});

$app->router->group('/api', [CorsMiddleware::class], function (\Siro\Core\Router $router): void {
    $router->post('/auth/register', [AuthController::class, 'register'])
        ->middleware([JsonMiddleware::class, 'throttle:30,1']);

    $router->post('/auth/login', [AuthController::class, 'login'])
        ->middleware([JsonMiddleware::class, 'throttle:60,1']);

    $router->post('/auth/refresh', [AuthController::class, 'refresh'])
        ->middleware([JsonMiddleware::class, 'throttle:30,1']);

    $router->post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])
        ->middleware([JsonMiddleware::class, 'throttle:10,1']);

    $router->post('/auth/reset-password', [AuthController::class, 'resetPassword'])
        ->middleware([JsonMiddleware::class, 'throttle:10,1']);

    $router->post('/auth/verify-email', [AuthController::class, 'verifyEmail'])
        ->middleware([JsonMiddleware::class, 'throttle:10,1']);

    $router->get('/auth/me', [AuthController::class, 'me'])
        ->middleware(['auth', 'throttle:120,1']);

    $router->post('/auth/logout', [AuthController::class, 'logout'])
        ->middleware(['auth', 'throttle:60,1']);
});
PHP;
    }

    private function refreshTokenMigrationTemplate(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

use Siro\Core\Schema;
use Siro\Core\DB\Blueprint;

return new class
{
    public function up(): void
    {
        Schema::create('refresh_tokens', function (Blueprint $t) {
            $t->id();
            $t->string('jti', 64)->unique();
            $t->bigint('user_id');
            $t->smallint('revoked')->default(0);
            $t->timestamp('expires_at');
            $t->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::drop('refresh_tokens');
    }
};
PHP;
    }

    private function authFieldsMigrationTemplate(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

use Siro\Core\Schema;
use Siro\Core\DB\Blueprint;

return new class
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->timestamp('email_verified_at')->nullable();
            $t->string('verification_token', 64)->nullable();
            $t->string('password_reset_token', 64)->nullable();
            $t->timestamp('password_reset_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropColumn('users', 'email_verified_at');
        Schema::dropColumn('users', 'verification_token');
        Schema::dropColumn('users', 'password_reset_token');
        Schema::dropColumn('users', 'password_reset_expires_at');
    }
};
PHP;
    }
}
