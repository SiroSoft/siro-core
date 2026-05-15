<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

/**
 * Generate complete auth system scaffolding.
 *
 * Creates migrations, AuthController, routes, User model, and
 * UserService for register/login/refresh/me/logout/forgot-password/
 * reset-password/verify-email endpoints.
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
        $this->updateUserService();
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

        // Create refresh_tokens table
        $refreshTokenMigration = $migrationDir . DIRECTORY_SEPARATOR . date('YmdHis', time()) . '_create_refresh_tokens_table.php';
        $this->write('Generated: database/migrations/..._create_refresh_tokens_table.php');
        file_put_contents($refreshTokenMigration, $this->refreshTokenMigrationTemplate());
        sleep(1); // Ensure unique timestamp

        // Add verification fields to users
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
        file_put_contents($path, $this->controllerTemplate());
        $this->write('Updated: app/Controllers/AuthController.php');
    }

    private function updateUserModel(): void
    {
        $path = $this->basePath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Models' . DIRECTORY_SEPARATOR . 'User.php';
        if (!is_file($path)) {
            $modelDir = dirname($path);
            if (!is_dir($modelDir)) {
                mkdir($modelDir, 0775, true);
            }
            file_put_contents($path, $this->modelTemplate());
            $this->write('Generated: app/Models/User.php');
            return;
        }

        $this->write('Exists: app/Models/User.php (skipped, already has fields)');
    }

    private function updateUserService(): void
    {
        $path = $this->basePath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Services' . DIRECTORY_SEPARATOR . 'User.php';
        $serviceDir = dirname($path);
        if (!is_dir($serviceDir)) {
            mkdir($serviceDir, 0775, true);
        }

        // Keep existing service, add verification methods
        $content = $this->serviceTemplate();
        file_put_contents($path, $content);
        $this->write('Updated: app/Services/User.php');
    }

    private function updateRoutes(): void
    {
        $path = $this->basePath . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'api.php';
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
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
use App\Services\User as UserService;
use Siro\Core\Auth\JWT;
use Siro\Core\DB;
use Siro\Core\Env;
use Siro\Core\Request;
use Siro\Core\Response;
use Throwable;

final class AuthController
{
    public function register(Request $request): Response
    {
        $request->validate([
            'name' => 'required|min:3|max:120',
            'email' => 'required|email|max:255',
            'password' => 'required|min:6|max:255',
        ]);

        $email = strtolower(trim($request->string('email')));

        // Check if email already exists
        $rows = User::where('email', '=', $email)->limit(1)->get();
        if ($rows !== []) {
            return Response::error('Validation failed', 422, [
                'email' => ['Email has already been taken'],
            ]);
        }

        $passwordHash = password_hash($request->string('password'), PASSWORD_DEFAULT);
        if ($passwordHash === false) {
            return Response::error('Unable to create account', 500);
        }

        try {
            $user = User::create([
                'name' => $request->string('name'),
                'email' => $email,
                'password' => $passwordHash,
                'status' => 1,
                'created_at' => date('Y-m-d H:i:s'),
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
            'password' => 'required|min:6|max:255',
        ]);

        $email = strtolower(trim($request->string('email')));
        $rows = User::where('email', '=', $email)->limit(1)->get();
        $userData = $rows[0] ?? null;

        if ($userData === null || !isset($userData['password']) || !is_string($userData['password'])) {
            return Response::error('Invalid credentials', 401);
        }

        if ((int) ($userData['status'] ?? 0) !== 1) {
            return Response::error('Account is inactive', 403);
        }

        if (!password_verify($request->string('password'), $userData['password'])) {
            return Response::error('Invalid credentials', 401);
        }

        $userId = (int) $userData['id'];
        $tokens = $this->tokenPair($userId);

        return Response::success([
            'token' => $tokens['token'],
            'refresh_token' => $tokens['refresh_token'],
            'token_type' => 'Bearer',
            'expires_in' => $tokens['ttl'],
            'user' => [
                'id' => $userId,
                'name' => (string) ($userData['name'] ?? ''),
                'email' => (string) ($userData['email'] ?? ''),
            ],
        ], 'Login successful');
    }

    public function refresh(Request $request): Response
    {
        $request->validate(['refresh_token' => 'required']);

        $refreshToken = $request->string('refresh_token');

        try {
            $claims = JWT::decode($refreshToken);
        } catch (Throwable) {
            return Response::error('Invalid or expired refresh token', 401);
        }

        if (($claims['type'] ?? '') !== JWT::TYPE_REFRESH) {
            return Response::error('Invalid token type', 401);
        }

        $userId = (int) ($claims['sub'] ?? 0);
        $jti = (string) ($claims['jti'] ?? '');

        if ($userId <= 0 || $jti === '') {
            return Response::error('Invalid token', 401);
        }

        // Check refresh token was not revoked
        $stored = DB::table('refresh_tokens')
            ->where('jti', '=', $jti)
            ->where('revoked', '=', 0)
            ->first();

        if ($stored === null) {
            return Response::error('Refresh token revoked', 401);
        }

        // Revoke old refresh token (rotation)
        DB::table('refresh_tokens')
            ->where('jti', '=', $jti)
            ->update(['revoked' => 1]);

        $tokens = $this->tokenPair($userId);

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
        $userId = (int) ($user['id'] ?? 0);

        if ($userId <= 0) {
            return Response::error('Unauthorized', 401);
        }

        if (!UserService::incrementTokenVersion($userId)) {
            return Response::error('Unable to revoke token', 500);
        }

        return Response::success(null, 'Logout successful. Token revoked.');
    }

    public function verifyEmail(Request $request): Response
    {
        $request->validate(['token' => 'required']);

        $token = $request->string('token');

        // Find user by verification token and hydrate to Model
        $rows = User::where('verification_token', '=', $token)->limit(1)->get();
        $user = isset($rows[0]) ? User::hydrate($rows[0]) : null;

        if ($user === null) {
            return Response::error('Invalid verification token', 400);
        }

        $user->update([
            'email_verified_at' => date('Y-m-d H:i:s'),
            'verification_token' => null,
        ]);

        return Response::success(null, 'Email verified successfully');
    }

    public function forgotPassword(Request $request): Response
    {
        $request->validate(['email' => 'required|email']);

        $email = strtolower(trim($request->string('email')));

        // Find user by email and hydrate to Model
        $rows = User::where('email', '=', $email)->limit(1)->get();
        $user = isset($rows[0]) ? User::hydrate($rows[0]) : null;

        if ($user !== null) {
            $resetToken = bin2hex(random_bytes(32));
            $user->update([
                'password_reset_token' => $resetToken,
                'password_reset_expires_at' => date('Y-m-d H:i:s', time() + 3600),
            ]);
        }

        // Always return success to prevent email enumeration
        return Response::success(null, 'If the email exists, a reset link has been sent.');
    }

    public function resetPassword(Request $request): Response
    {
        $request->validate([
            'token' => 'required',
            'password' => 'required|min:6|max:255',
        ]);

        $token = $request->string('token');

        // Find user by reset token and hydrate to Model
        $rows = User::where('password_reset_token', '=', $token)->limit(1)->get();
        $user = isset($rows[0]) ? User::hydrate($rows[0]) : null;

        if ($user === null) {
            return Response::error('Invalid or expired reset token', 400);
        }

        $userData = $user->toArray();
        $expiresAt = (string) ($userData['password_reset_expires_at'] ?? '');

        if ($expiresAt !== '' && strtotime($expiresAt) < time()) {
            return Response::error('Reset token has expired', 400);
        }

        $passwordHash = password_hash($request->string('password'), PASSWORD_DEFAULT);
        if ($passwordHash === false) {
            return Response::error('Unable to reset password', 500);
        }

        $user->update([
            'password' => $passwordHash,
            'password_reset_token' => null,
            'password_reset_expires_at' => null,
            'token_version' => ($userData['token_version'] ?? 1) + 1,
        ]);

        return Response::success(null, 'Password reset successfully');
    }

    /** @return array{token:string,refresh_token:string,ttl:int} */
    private function tokenPair(int $userId): array
    {
        $ttl = max(60, (int) Env::get('JWT_TTL', '3600'));
        $refreshTtl = max(3600, (int) Env::get('JWT_REFRESH_TTL', '604800'));

        $user = User::find($userId);
        $tokenVersion = (int) ($user?->token_version ?? 1);

        $token = JWT::encodeAccess($userId, $tokenVersion, $ttl);
        $refreshToken = JWT::encodeRefresh($userId, $tokenVersion, $refreshTtl);

        // Store refresh token
        $jti = bin2hex(random_bytes(16));
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
    ];

    /** @var array<int, string> */
    protected array $fillable = [
        'name',
        'email',
        'password',
        'status',
        'token_version',
        'email_verified_at',
        'verification_token',
        'password_reset_token',
        'password_reset_expires_at',
    ];
}
PHP;
    }

    private function serviceTemplate(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User as UserModel;

final class User
{
    public static function incrementTokenVersion(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $user = UserModel::find($userId);
        if ($user === null) {
            return false;
        }

        return $user->update(['token_version' => ($user->token_version ?? 0) + 1]) > 0;
    }
}
PHP;
    }

    private function routesTemplate(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

use App\Controllers\UserController;
use App\Controllers\AuthController;
use Siro\Core\Middleware\CorsMiddleware;
use Siro\Core\Middleware\JsonMiddleware;

$app->router->get('/', function (): array {
    return [
        'success' => true,
        'message' => 'Siro API Framework is running',
        'data' => [
            'name' => 'Siro API Framework',
            'version' => Console::getVersion(),
            'php' => PHP_VERSION,
        ],
        'meta' => [],
    ];
});

$app->router->group('/api', [CorsMiddleware::class], function ($router): void {
    // Public auth routes
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

    // Protected auth routes
    $router->get('/auth/me', [AuthController::class, 'me'])
        ->middleware(['auth', 'throttle:120,1']);

    $router->post('/auth/logout', [AuthController::class, 'logout'])
        ->middleware(['auth', 'throttle:60,1']);

    // CRUD routes
    $router->get('/users', [UserController::class, 'index'])->cache(60);
    $router->get('/users/{id}', [UserController::class, 'show'])->cache(60);

    $router->post('/users', [UserController::class, 'store'])
        ->middleware([JsonMiddleware::class, 'auth', 'throttle:60,1']);

    $router->put('/users/{id}', [UserController::class, 'update'])
        ->middleware([JsonMiddleware::class, 'auth', 'throttle:60,1']);

    $router->delete('/users/{id}', [UserController::class, 'delete'])
        ->middleware(['auth', 'throttle:60,1']);
});
PHP;
    }

    private function refreshTokenMigrationTemplate(): string
    {
        $class = 'CreateRefreshTokensTable';
        return <<<PHP
<?php

declare(strict_types=1);

use Siro\Core\Schema;
use Siro\Core\DB\Blueprint;

return new class
{
    public function up(): void
    {
        Schema::create('refresh_tokens', function (Blueprint \$t) {
            \$t->id();
            \$t->string('jti', 64)->unique();
            \$t->bigint('user_id');
            \$t->smallint('revoked')->default(0);
            \$t->timestamp('expires_at');
            \$t->timestamp('created_at')->useCurrent();
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
