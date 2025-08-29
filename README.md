# 🚀 CRUD Generator Restful API Laravel 12

Generator otomatis untuk membuat **CRUD Restful API** berbasis **Laravel 12**, dengan fitur:

✅ Dynamic CRUD dari migrasi  
✅ Pagination (limit & offset)  
✅ Search (multi-field)  
✅ Filter (multi-field)  
✅ Sorting (multi-field)  
✅ Select field dinamis  
✅ Eager loading relasi dinamis  
✅ Date range filter dinamis  
✅ Soft delete control  
✅ Response JSON standar (tanpa field sensitif)  
✅ Export (CSV, Excel, PDF)  
✅ Caching query  
✅ Rate limiting per user  
✅ Logging & Monitoring (Telescope)  
✅ Unit & Feature Testing  
✅ API Versioning (v1, v2, …)  
✅ Auth dengan Sanctum (email/username login)  
✅ Role & Permission (Spatie)  
✅ Tempat untuk fungsi kustom  

---

## 📦 Instalasi

1. **Clone & install dependency**
   ```bash
   git clone <repo_url> project-api
   cd project-api
   composer install
   cp .env.example .env
   php artisan key:generate
   ```

2. **Pasang package tambahan**
   ```bash
   php artisan install:api
   composer require laravel/sanctum
   composer require spatie/laravel-permission
   composer require maatwebsite/excel:^3.1
   composer require barryvdh/laravel-dompdf
   composer require laravel/telescope --dev
   ```

3. **Publish config & migrasi**
   ```bash
   php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
   php artisan vendor:publish --provider="Maatwebsite\Excel\ExcelServiceProvider"
   php artisan vendor:publish --provider="Barryvdh\DomPDF\ServiceProvider"
   php artisan config:publish cors
   php artisan migrate
   ```

---

## ⚙️ Konfigurasi `.env`

Contoh konfigurasi:

```dotenv
APP_NAME="Laravel"
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_TIMEZONE=Asia/Jakarta
APP_URL=http://localhost

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=laravel12_api
DB_USERNAME=root
DB_PASSWORD=

CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=file

SANCTUM_STATEFUL_DOMAINS=localhost,127.0.0.1
SESSION_DOMAIN=localhost

TELESCOPE_ENABLED=true
```

---

## 🔨 Cara Penggunaan

1. **Buat migrasi terlebih dahulu**
   ```bash
   php artisan make:migration create_products_table
   php artisan migrate
   ```

2. **Buat model (trigger CRUD Generator)**
   ```bash
   php artisan make:model Product
   ```
   > Otomatis akan dibuat: Controller, Request, Resource, Repository, Service, Policy, Export, PDF View, Test, dan route di `/api/v1/products`.

3. **Coba endpoint**
   - `GET /api/v1/products?per_page=10`
   - `GET /api/v1/products?limit=10&offset=20`
   - `GET /api/v1/products?search=router&search_fields=name,description`
   - `GET /api/v1/products?filter[status]=active&sort=name,-price`
   - `GET /api/v1/products?include=category`
   - `GET /api/v1/products/export?format=csv|xlsx|pdf`
   - `PUT /api/v1/products/{id}/restore`

---

## 🔑 Autentikasi

Menggunakan **Sanctum**:  
- **Register**: `POST /api/v1/auth/register`  
- **Login** (email atau username): `POST /api/v1/auth/login`  
- **Logout**: `POST /api/v1/auth/logout`  

Contoh login:
```json
{
  "login": "admin@example.com",
  "password": "password"
}
```

---

## 👮 Role & Permission

Menggunakan **Spatie Laravel Permission**:  
```php
$user->assignRole('admin');
$user->givePermissionTo('create products');
```

---

## 📊 Monitoring & Logging

- Gunakan **Laravel Telescope** (`/telescope`) untuk monitoring query, log, request.  
- Logging default via `storage/logs/laravel.log`.

---

## 🧪 Testing

Jalankan unit & feature test:
```bash
php artisan test
```

---

## 📂 Struktur Penting

```
app/
 ├── Http/
 │    ├── Controllers/Api/V1/   → Controller API
 │    ├── Requests/             → Request Validasi
 │    └── Resources/            → Resource JSON
 ├── Models/                    → Eloquent Model
 ├── Policies/                  → Policy (Authorization)
 ├── Repositories/              → Query Repository
 └── Services/                  → Layanan (logic + export)
resources/views/exports/        → View untuk PDF Export
routes/api.php                  → API Routes (v1, v2, ...)
```

---

## 🚀 Deployment

1. Set `.env` ke mode `production`.  
2. Jalankan cache & optimize:
   ```bash
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```
3. Gunakan supervisor/queue worker untuk job & export.  

---

## ✨ Catatan

- **Migrasi harus ada dulu** sebelum `php artisan make:model` dijalankan.  
- Semua **file CRUD** dibuat otomatis sesuai nama tabel & field di migrasi.  
- Untuk **fungsi kustom**, gunakan `ServiceKustom` atau buat Trait di dalam `Services`.  
- Mendukung **API Versioning** (`/api/v1/...`, `/api/v2/...`).

---

## 📜 Lisensi

Project ini open-source untuk pembelajaran dan pengembangan internal.
