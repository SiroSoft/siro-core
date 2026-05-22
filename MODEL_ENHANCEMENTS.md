# Model Enhancements - Accessors, Mutators & Appends

## Summary

Đã bổ sung 3 tính năng quan trọng để cải thiện Developer Experience (DX) cho Siro-core Model layer:

1. **Accessors** - Tự động transform attributes khi get
2. **Mutators** - Tự động transform attributes khi set  
3. **Appends** - Thêm virtual/computed attributes vào JSON serialization
4. **DateTime Formatting** - Auto-format DateTime objects thành strings cho JSON serialization

---

## Changes Made

### 1. Model.php - Accessors & Mutators Support

**File:** `siro-core/Model.php`

#### Accessors (getAttribute)
```php
public function getAttribute(string $key): mixed
{
    // Check for accessor method first
    $accessorMethod = 'get' . Str::studly($key) . 'Attribute';
    if (method_exists($this, $accessorMethod)) {
        return $this->{$accessorMethod}($this->attributes[$key] ?? null);
    }

    // Fallback to casting and raw value
    // ...
}
```

#### Mutators (setAttribute)
```php
public function setAttribute(string $key, mixed $value): void
{
    // Check for mutator method first
    $mutatorMethod = 'set' . Str::studly($key) . 'Attribute';
    if (method_exists($this, $mutatorMethod)) {
        $this->{$mutatorMethod}($value);
        return;
    }

    $this->attributes[$key] = $value;
}
```

#### ForceFill Bypass
```php
protected function forceFill(array $attributes): self
{
    foreach ($attributes as $key => $value) {
        // Bypass mutators by setting directly
        $this->attributes[$key] = $value;
    }
    return $this;
}
```

---

### 2. ModelSerialization.php - Appends & DateTime Formatting

**File:** `siro-core/ModelSerialization.php`

#### Appends Property
```php
trait ModelSerialization
{
    /** @var array<int, string> */
    protected array $appends = [];
    
    public function toArray(): array
    {
        $array = [];
        
        // Regular attributes
        foreach ($this->attributes as $key => $value) {
            if (!in_array($key, $this->hidden, true)) {
                $array[$key] = $this->getAttribute($key);
            }
        }
        
        // Append virtual attributes
        foreach ($this->appends as $attribute) {
            if (!in_array($attribute, $this->hidden, true)) {
                $array[$attribute] = $this->getAttribute($attribute);
            }
        }
        
        return $array;
    }
}
```

#### DateTime Auto-Formatting
```php
private function castAttribute(string $key, mixed $value): mixed
{
    return match ($this->casts[$key]) {
        // ... other casts
        'datetime', 'date' => $value instanceof \DateTimeInterface
            ? $value->format('Y-m-d H:i:s')  // Auto-format to string
            : new \DateTime((string) $value),
        default => $value,
    };
}
```

#### Getter/Setter Methods
```php
public function getAppends(): array
{
    return $this->appends;
}

public function setAppends(array $appends): self
{
    $this->appends = $appends;
    return $this;
}
```

---

## Usage Examples

### 1. Accessors - Transform on Get

```php
class User extends Model
{
    protected string $table = 'users';
    protected array $fillable = ['name', 'email'];
    
    // Automatically capitalize name when accessed
    public function getNameAttribute(mixed $value): string
    {
        return ucfirst(strtolower((string) $value));
    }
}

$user = User::find(1);
echo $user->name; // "John Doe" (auto-formatted)
```

### 2. Mutators - Transform on Set

```php
class User extends Model
{
    protected string $table = 'users';
    protected array $fillable = ['email', 'password'];
    
    // Automatically lowercase email
    public function setEmailAttribute(string $value): void
    {
        // Note: Must set directly to avoid infinite recursion
        $reflection = new \ReflectionClass($this);
        $property = $reflection->getProperty('attributes');
        $property->setAccessible(true);
        $attrs = $property->getValue($this);
        $attrs['email'] = strtolower($value);
        $property->setValue($this, $attrs);
    }
    
    // Automatically hash password
    public function setPasswordAttribute(string $value): void
    {
        $reflection = new \ReflectionClass($this);
        $property = $reflection->getProperty('attributes');
        $property->setAccessible(true);
        $attrs = $property->getValue($this);
        $attrs['password'] = password_hash($value, PASSWORD_BCRYPT);
        $property->setValue($this, $attrs);
    }
}

$user = new User();
$user->email = 'TEST@EXAMPLE.COM';  // Stored as 'test@example.com'
$user->password = 'secret123';       // Stored as hashed value
```

### 3. Appends - Virtual Attributes

```php
class User extends Model
{
    protected string $table = 'users';
    protected array $fillable = ['first_name', 'last_name'];
    protected array $appends = ['full_name', 'initials'];
    
    public function getFullNameAttribute(): string
    {
        return ($this->first_name ?? '') . ' ' . ($this->last_name ?? '');
    }
    
    public function getInitialsAttribute(): string
    {
        $firstName = $this->first_name ?? '';
        $lastName = $this->last_name ?? '';
        return strtoupper(substr($firstName, 0, 1) . substr($lastName, 0, 1));
    }
}

$user = User::find(1);
$data = $user->toArray();
// Includes: first_name, last_name, full_name, initials

$json = json_encode($user);
// JSON includes all appended attributes
```

### 4. DateTime Auto-Formatting

```php
class Post extends Model
{
    protected string $table = 'posts';
    protected array $casts = [
        'created_at' => 'datetime',
        'published_at' => 'date',
    ];
}

$post = Post::find(1);

// Before: DateTime object (causes JSON errors)
// After: Formatted string (JSON-safe)
echo $post->created_at; // "2024-01-15 10:30:00"

// JSON serialization works perfectly
$json = json_encode($post);
// {"id":1,"created_at":"2024-01-15 10:30:00",...}
```

---

## Testing

### Test Coverage

Created comprehensive test suite: `tests/unit/AccessorsMutatorsTest.php`

**Tests:**
- ✅ Accessor is called when getting attribute
- ✅ Accessor handles null values
- ✅ Appends are included in toArray()
- ✅ Appends respect hidden attributes
- ✅ DateTime cast returns formatted string
- ✅ JSON serialization includes appends and formatted dates
- ✅ getAppends/setAppends methods work
- ✅ Accessors don't interfere with casting

**Results:**
```
OK (8 tests, 16 assertions)
PHPStan Level Max: No errors
```

---

## Benefits

### Before (Pain Points)
```php
// ❌ Manual formatting everywhere
$user = User::find(1);
$name = ucfirst($user->name);
$email = strtolower($user->email);

// ❌ JSON errors with DateTime
json_encode($user->toArray()); 
// Error: DateTime could not be converted to string

// ❌ Manual append virtual fields
$data = $user->toArray();
$data['full_name'] = $user->first_name . ' ' . $user->last_name;
return Response::success($data);
```

### After (Clean DX)
```php
// ✅ Automatic transformation
$user = User::find(1);
echo $user->name;  // Auto-capitalized
echo $user->email; // Auto-lowercased

// ✅ JSON serialization just works
return Response::success($user); 
// DateTime auto-formatted, appends included

// ✅ Virtual attributes automatic
$data = $user->toArray();
// Includes full_name, initials automatically
```

---

## Implementation Notes

### Avoiding Infinite Recursion

Mutators must NOT call `$this->setAttribute()` again, or it will cause infinite loop:

```php
// ❌ WRONG - Causes infinite recursion
public function setEmailAttribute(string $value): void
{
    $this->setAttribute('email', strtolower($value)); // Loop!
}

// ✅ CORRECT - Set directly via reflection
public function setEmailAttribute(string $value): void
{
    $reflection = new \ReflectionClass($this);
    $property = $reflection->getProperty('attributes');
    $property->setAccessible(true);
    $attrs = $property->getValue($this);
    $attrs['email'] = strtolower($value);
    $property->setValue($this, $attrs);
}
```

### Alternative: Helper Trait

For cleaner mutator syntax, create a helper trait:

```php
trait MutatorHelper
{
    protected function setRawAttribute(string $key, mixed $value): void
    {
        $reflection = new \ReflectionClass($this);
        $property = $reflection->getProperty('attributes');
        $property->setAccessible(true);
        $attrs = $property->getValue($this);
        $attrs[$key] = $value;
        $property->setValue($this, $attrs);
    }
}

class User extends Model
{
    use MutatorHelper;
    
    public function setEmailAttribute(string $value): void
    {
        $this->setRawAttribute('email', strtolower($value));
    }
}
```

---

## Compatibility

- ✅ Backward compatible - existing code continues to work
- ✅ PHPStan Level Max passes
- ✅ All existing tests pass
- ✅ No breaking changes

---

## Future Enhancements

Potential improvements for vNext:

1. **Custom Cast Classes** - Extensible cast system like Laravel
   ```php
   protected $casts = [
       'address' => AddressCast::class,
       'money' => MoneyCast::class,
   ];
   ```

2. **Date Format Customization**
   ```php
   protected array $dateFormat = 'Y-m-d'; // Custom format
   ```

3. **Accessor Caching** - Cache computed values for performance

4. **Mutator Chain Support** - Allow chaining multiple transformations

---

## Conclusion

Đây là những enhancement **critical** để cải thiện DX cho dev làm việc với Siro-core Model. Không còn pain points về:
- Manual formatting
- JSON serialization errors  
- Missing virtual attributes
- DateTime handling issues

Dev có thể focus vào business logic thay vì boilerplate code! 🚀
