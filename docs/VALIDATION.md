# Validation

siro-core provides a lightweight, zero-dependency validation engine via `Validator` and `FormRequest`. Validation errors are automatically translated via `Lang` and fall back to English defaults.

---

## Basic Usage

### Validator::make()

```php
use Siro\Core\Validator;

$input = [
    'email' => 'user@example.com',
    'password' => 'secret123',
    'age' => '25',
];

$rules = [
    'email'    => 'required|email',
    'password' => 'required|min:8',
    'age'      => 'required|numeric|min:18',
];

$errors = Validator::make($input, $rules);

if ($errors === []) {
    // all good
} else {
    // $errors = ['email' => ['Email is required'], ...]
}
```

### $request->validate()

Throws `ValidationException` (422 response) on failure. Returns only the validated fields.

```php
public function store(Request $request): Response
{
    $data = $request->validate([
        'title'  => 'required|min:3|max:255',
        'body'   => 'required',
        'email'  => 'required|email|unique:users,email',
    ]);

    // $data contains only the validated keys
    User::create($data);

    return new Response(['success' => true], 201);
}
```

---

## Available Rules

| Rule        | Description                                                 |
|-------------|-------------------------------------------------------------|
| `required`  | Field must be present and non-empty                         |
| `nullable`  | Allow null/empty values (skips other rules when null)       |
| `email`     | Must be a valid email address                               |
| `numeric`   | Must be numeric (`is_numeric`)                              |
| `integer`   | Must be an integer                                          |
| `date`      | Must be a valid date (timestamp or strtotime-parsable)      |
| `url`       | Must be a valid URL                                         |
| `file`      | Must be a valid uploaded file                               |
| `image`     | Must be an image (used with `file`)                         |
| `min:N`     | Minimum length/ value/ file size in KB                       |
| `max:N`     | Maximum length/ value/ file size in KB                       |
| `confirmed` | Field must match `{field}_confirmation`                     |
| `in:a,b,c`  | Value must be one of the comma-separated list               |
| `regex:/pattern/` | Must match the given regular expression             |
| `unique:table,column` | Value must not exist in the database table      |
| `exists:table,column` | Value must exist in the database table           |
| `required_if:field,value` | Required when another field equals a value    |

### Rule Examples

```php
$rules = [
    // String rules
    'username' => 'required|min:3|max:50',
    'email'    => 'required|email|unique:users,email',
    'bio'      => 'nullable|max:1000',

    // Numeric rules
    'age'    => 'required|integer|min:18|max:120',
    'price'  => 'required|numeric|min:0.01',
    'rating' => 'required|numeric|min:1|max:5',

    // Enumerated values
    'role' => 'required|in:admin,editor,viewer',

    // Pattern matching
    'phone' => 'required|regex:/^\+?[0-9]{10,15}$/',

    // Database checks
    'category_id' => 'required|integer|exists:categories,id',
    'slug'        => 'required|unique:posts,slug',

    // Confirmation
    'password' => 'required|min:8|confirmed',

    // Conditional requirement
    'coupon_code' => 'required_if:has_coupon,yes',

    // File uploads
    'avatar' => 'required|file|image|max:2048', // 2 MB max
    'document' => 'nullable|file|max:10240',    // 10 MB max
];
```

### File Validation

File rules are validated against `UploadedFile` instances. The `file` rule must come first for file fields.

```php
$rules = [
    'photo' => 'required|file|image|min:10|max:2048',
];
```

- `min` / `max` on files refer to **kilobytes**
- `image` checks the MIME type starts with `image/`
- Files are automatically merged with body data in `$request->validate()`

---

## Custom Error Messages

### Validator::messages() (new in v0.24)

Override default error messages globally before validation:

```php
Validator::messages([
    'required' => ':field is mandatory',
    'email'    => ':field must be a valid email address',
    'min'      => ':field needs at least :min characters',
    'unique'   => ':field is already taken',
    'in'       => ':field must be one of: :values',
]);
```

The `validation.X` keys map to translation keys used by `Lang::get()`. Available keys:

| Key                    | Default message                         |
|------------------------|-----------------------------------------|
| `validation.required`  | `:field is required`                    |
| `validation.email`     | `:field must be a valid email`          |
| `validation.numeric`   | `:field must be numeric`                |
| `validation.integer`   | `:field must be an integer`             |
| `validation.date`      | `:field must be a valid date`           |
| `validation.url`       | `:field must be a valid URL`            |
| `validation.regex`     | `:field format is invalid`              |
| `validation.min`       | `:field must be at least :min`          |
| `validation.max`       | `:field must not exceed :max`           |
| `validation.unique`    | `:field already exists`                 |
| `validation.exists`    | `:field does not exist`                 |
| `validation.confirmed` | `:field confirmation does not match`    |
| `validation.in`        | `:field must be one of: :values`        |
| `validation.file`      | `:field must be a valid file`           |
| `validation.array`     | `:field must not be an array`           |

---

## Custom Rules

### Validator::extend()

Register a custom rule with a callback. Return `true` for valid, or a string error message (use `:field` as placeholder).

```php
Validator::extend('phone', function ($value, $field, $input, $parameter) {
    return preg_match('/^\+?[0-9]{10,15}$/', (string) $value)
        ? true
        : ':field is not a valid phone number';
});

// Then use it:
$rules = ['contact' => 'required|phone'];
```

```php
Validator::extend('strong_password', function ($value) {
    if (strlen((string) $value) < 8) {
        return ':field must be at least 8 characters';
    }
    if (!preg_match('/[A-Z]/', (string) $value)) {
        return ':field must contain an uppercase letter';
    }
    if (!preg_match('/[0-9]/', (string) $value)) {
        return ':field must contain a number';
    }
    return true;
});

$rules = ['password' => 'required|strong_password|confirmed'];
```

Custom rules are checked **before** built-in rules, so they can override.

---

## FormRequest Pattern

Create a dedicated form request class for complex validation logic:

```php
namespace App\Requests;

use Siro\Core\FormRequest;

class StoreUserRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name'     => 'required|min:2|max:100',
            'email'    => 'required|email|unique:users,email',
            'password' => 'required|min:8|confirmed',
            'role'     => 'required|in:admin,editor',
        ];
    }

    public function authorize(): bool
    {
        return $this->request->user() !== null
            && $this->request->user()['role'] === 'admin';
    }

    public function messages(): array
    {
        return [
            'role.in' => 'The role must be admin or editor.',
        ];
    }
}
```

Then type-hint in your controller for automatic validation:

```php
use App\Requests\StoreUserRequest;

class UserController extends Controller
{
    public function store(StoreUserRequest $req): Response
    {
        $data = $req->validated(); // validated data only
        // or
        $data = $req->all();       // all validated input

        if ($req->fails()) {
            return new Response(['errors' => $req->errors()], 422);
        }

        $user = User::create($data);
        return new Response($user, 201);
    }
}
```

---

## ValidationException Handling

When `$request->validate()` fails, a `ValidationException` is thrown. The framework catches it and returns a consistent JSON 422 response.

```php
use Siro\Core\ValidationException;

throw new ValidationException(
    ['email' => ['The email field is required.']],
    'Validation failed'
);
```

Manually catching in controllers:

```php
use Siro\Core\ValidationException;

public function store(Request $request): Response
{
    try {
        $data = $request->validate([
            'email' => 'required|email',
        ]);
    } catch (ValidationException $e) {
        // Access structured errors
        $errors = $e->errors(); // ['email' => ['Email is required']]

        // Or use the built-in 422 response
        return $e->toResponse();
    }
}
```

---

## Error Response Format

All validation failures produce a standardized JSON response with HTTP 422:

```json
{
    "success": false,
    "message": "Validation failed",
    "data": null,
    "errors": {
        "email": [
            "Email is required"
        ],
        "password": [
            "Password must be at least 8 characters",
            "Password confirmation does not match"
        ]
    },
    "meta": []
}
```

Generated by `ValidationException::toResponse()`:

```php
public function toResponse(): Response
{
    return new Response([
        'success' => false,
        'message' => $this->getMessage(),
        'data'    => null,
        'errors'  => $this->errors,
        'meta'    => [],
    ], 422);
}
```
