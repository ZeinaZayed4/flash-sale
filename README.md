<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

# Flash Sale Checkout System
A Laravel-based API for handling flash sales with high concurrency,
preventing overselling through pessimistic locking, temporary holds with expiry, and idempotent payment webhooks.

## Features
- No overselling under concurrent requests.
- 2-minute temporary stock reservations.
- Idempotent payment webhooks.
- Automatic stock release on expiry/failure.

## Quick Start
1. **Install**
   ```
   git clone https://github.com/ZeinaZayed4/flash-sale
   cd flash-sale
   composer install
   cp .env.example .env
   ```
2. **Configure Database**
   - Edit `.env`:
      ```
      DB_DATABASE=flash_sale
      DB_USERNAME=root
      DB_PASSWORD=your_password
        
      CACHE_DRIVER=database
      QUEUE_CONNECTION=database
      ```
3. **Setup Database**
    ```
   php artisan key:generate
   php artisan migrate:fresh --seed
   ```
4. **Run**
    - Open 3 terminals:
        ```
        php artisan serve
      
        php artisan schedule:work
      
        php artisan queue:work
        ```

## API Usage
- **Get Product**
  ```
  GET /api/products/1
  ```
- **Create Hold(2-minute reservation)**
  ```
  POST /api/holds
  {
    "product_id": 1,
    "quantity": 5
  }
  ```
- **Create Order**
  ```
  POST /api/orders
  {
    "hold_id": 1
  }
  ```
- **Payment Webhook**
  ```
  POST /api/payments/webhook
  {
    "idempotency_key": "payment_1234",
    "order_id": 1,
    "status": "success"
  }
  ```

## Testing
```
# Run all tests
./vendor/bin/pest

# Run specific test file
./vendor/bin/pest tests/Feature/ProductTest.php

# With coverage
./vendor/bin/pest --coverage
```

## How It Works

### Stock Management
- **Pessimistic Locking**: `lockForUpdate()` prevents race conditions.
- **Two Stock Columns**: `total_stock` (never changes) and `available_stock` (decrements with holds).
- **Cache**: 5-second TTL for fast reads, invalidated on changes

### Hold System
- Holds expire after 2 minutes.
- Background job releases expired holds every minute.
- Each hold can only be used once (`is_consumed` flag).

### Payment Webhooks
- **Idempotency**: Unique database constraint on `idempotency_key`.
- **Out-of-order safe**: Webhooks can arrive before order creation.
- **Retry logic**: Pending webhooks retried every 30 seconds.
