# Optimus Parking Management System 🚗💳

Sistem Manajemen & Registrasi Member Parkir Berlangganan dengan Integrasi Payment Gateway **iPaymu v2 (QRIS & Virtual Account)** dan Database **PostgreSQL**.

---

## 📑 Daftar Isi
1. [Fitur Utama](#-fitur-utama)
2. [Panduan Instalasi Step-by-Step (Dari Clone Sampai Sukses Bayar)](#-panduan-instalasi-step-by-step)
3. [Arsitektur & Konfigurasi](#-arsitektur--konfigurasi)
4. [Panduan Pengujian Postman (Manual Webhook Testing)](#-panduan-pengujian-postman-manual-webhook-testing)
   - [Cara Import Collection](#1-cara-import-collection-ke-postman)
   - [Endpoint Webhook & Contoh Payload](#2-endpoint-webhook-callback)
   - [Contoh Response](#3-contoh-response-api)
5. [Struktur File Penting](#-struktur-file-penting)

---

## 🚀 Fitur Utama
* **Pendaftaran Member Baru ([input-baru.php](input-baru.php)):**
  * Registrasi member parkir (Mobil, Motor, Truk, Bus) dengan pilihan paket langganan lengkap (1 Bulan, 2 Bulan, 3 Bulan, 6 Bulan, 1 Tahun).
  * Opsi metode pembayaran: **Tunai (Cash)** & **Online (iPaymu QRIS / Virtual Account)**.
* **Perpanjangan Masa Aktif Member ([perpanjang-member.php](perpanjang-member.php)):**
  * Perpanjangan masa aktif member dengan opsi pembayaran **Tunai** atau **iPaymu (QRIS & Virtual Account)**.
  * Tagihan perpanjangan online langsung menerbitkan barcode QRIS / nomor VA dan status otomatis sinkron saat dibayar.
* **Integrasi iPaymu Service ([services/IPaymuService.php](services/IPaymuService.php)):**
  * Auto-generation HMAC-SHA256 signature.
  * Auto-extraction barcode QRIS Base64 (`data:image/png;base64,...`) sehingga QR code tampil jernih di browser tanpa terblokir `X-Frame-Options`.
  * Auto-sanitasi format nomor telepon & email.
* **Manajemen Status Pembayaran:**
  * Status **`PENDING`** saat tagihan dibuat (kendaraan belum aktif).
  * Status otomatis berubah menjadi **`LUNAS / AKTIF`** saat pembayaran diselesaikan melalui webhook atau tombol sinkronisasi kasir.
* **Halaman Pembayaran & Re-generate Tagihan ([bayar-member.php](bayar-member.php)):**
  * Menampilkan barcode QRIS dan nomor VA kapan pun jika member lupa link pembayarannya.
  * Tombol *Cek Status Pembayaran (Sync iPaymu)* & *Generate Ulang Tagihan*.
* **Dual Audit Logging:**
  * File log sistem di `logs/ipaymu.log`.
  * Tabel audit database PostgreSQL di `ipaymu_logs`.

---

## 🛠️ Panduan Instalasi Step-by-Step

Ikuti langkah-langkah berikut dari awal *clone repository* hingga sistem siap digunakan dan transaksi pembayaran sukses:

### 1. Prasyarat Sistem
* **Web Server:** Laragon / XAMPP (Apache + PHP 7.4 / 8.x) dengan ekstensi aktif: `pdo_pgsql`, `curl`, `json`, `mbstring`.
* **Database Server:** PostgreSQL (v12 ke atas) port 5432.
* **Composer:** Package manager PHP terpasang.

---

### 2. Clone Repository & Masuk ke Direktori
Buka terminal / Git Bash di folder `www` web server Anda:
```bash
cd d:/laragon/www/yeye
git clone https://github.com/username/optimus.git
cd optimus
```

---

### 3. Install Dependensi PHP
Jalankan Composer untuk mengunduh library pendukung (seperti PHPMailer):
```bash
composer install
```

---

### 4. Setup & Import Database PostgreSQL
1. Buat database baru bernama `optimus_parking` di PostgreSQL:
   ```bash
   createdb -U postgres -h 127.0.0.1 optimus_parking
   ```
2. Import skema tabel, view, dan data awal dari file **`schema.sql`**:
   ```bash
   psql -U postgres -h 127.0.0.1 -d optimus_parking -f schema.sql
   ```

---

### 5. Konfigurasi Sistem Mandiri (`config.php`)
Buka file **`config.php`** di root folder dan sesuaikan dengan database serta kredensial akun iPaymu Anda sendiri:

#### A. Pengaturan Database PostgreSQL:
```php
define('DB_DRIVER', 'pgsql');
define('DB_HOST', '127.0.0.1');     // Host database (default: 127.0.0.1 atau localhost)
define('DB_PORT', '5432');          // Port PostgreSQL (default: 5432)
define('DB_NAME', 'optimus_parking'); // Nama database Anda
define('DB_USER', 'postgres');       // Username database Anda
define('DB_PASS', 'postgres');       // Password database Anda
```

#### B. Pengaturan Akun iPaymu (Sandbox / Live Production):
Dapatkan **Nomor VA** dan **API Key** dari Dashboard iPaymu Anda:
* **Dashboard Sandbox:** [https://sandbox.ipaymu.com](https://sandbox.ipaymu.com) (Menu Integrasi / API)
* **Dashboard Production:** [https://my.ipaymu.com](https://my.ipaymu.com) (Menu Integrasi / API)

```php
define('IPAYMU_VA', '0000007861189600');                              // Ganti dengan Nomor VA iPaymu Anda
define('IPAYMU_API_KEY', 'SANDBOX87CC2DFA-FB00-42AB-9051-902CBA8E1E7E'); // Ganti dengan API Key iPaymu Anda
define('IPAYMU_SANDBOX', true); // Set 'true' untuk Sandbox, ubah ke 'false' jika beralih ke Live Production
```

---

## 🔍 Cara Memeriksa Log Transaksi & Webhook (Audit Logging)

Sistem menyediakan **Dual Logging** (pencatatan ganda ke file teks dan database) untuk memudahkan pelacakan error, debug request/response, dan memverifikasi notifikasi webhook pembayaran.

### Cara 1: Memeriksa File Log Teks (`logs/ipaymu.log`)
Semua aktivitas API (request, response, signature HMAC, dan payload webhook) otomatis dicatat ke file:
📁 **`optimus/logs/ipaymu.log`**

* **Melihat log secara real-time via PowerShell (Windows):**
  ```powershell
  Get-Content -Path .\logs\ipaymu.log -Wait -Tail 30
  ```
* **Melihat log via Git Bash / Linux:**
  ```bash
  tail -f logs/ipaymu.log
  ```
* **Format Catatan di File Log:**
  ```text
  [2026-08-21 11:22:41] [STK/20260821/6A87D28F478BC] [DIRECT_PAYMENT] [HTTP 200] [SUCCESS]
  ENDPOINT: https://sandbox.ipaymu.com/api/v2/payment/direct
  REQUEST: {"name":"MEMBER","amount":300000,...}
  RESPONSE: {"Status":200,"Success":true,"Data":{"TransactionId":226590,"QrImage":"..."}}
  ----------------------------------------------------------------------
  ```

### Cara 2: Memeriksa Tabel Log di Database (`ipaymu_logs`)
Setiap payload juga tersimpan rapi dalam tabel database PostgreSQL sehingga dapat difilter atau dianalisis:

* **Query melihat 10 transaksi / webhook terakhir:**
  ```sql
  SELECT id, reference_id, action_type, http_code, status, created_at 
  FROM ipaymu_logs 
  ORDER BY created_at DESC 
  LIMIT 10;
  ```
* **Query mencari log berdasarkan Nomor Transaksi (`reference_id`):**
  ```sql
  SELECT * FROM ipaymu_logs WHERE reference_id = 'STK/20260821/6A87D28F478BC';
  ```

---

### 6. Buka Aplikasi di Browser & Login
1. Buka browser dan akses:
   👉 **`http://localhost:81/yeye/optimus/login.php`** *(sesuaikan port web server Anda)*
2. Masukkan akun default:
   * **Username:** `admin`
   * **Password:** `admin`
   * **Lokasi / Cabang:** `PUSAT`
3. Klik **Masuk ke Sistem**.

---

### 7. Alur Pendaftaran & Transaksi Pembayaran (Sampai Sukses)

1. **Input Member Baru:**
   * Klik menu **Input Member Baru** ([input-baru.php](input-baru.php)).
   * Isi Data Personal (Nama, Email, No. Telepon).
   * Masukkan Data Kendaraan (Nomor Polisi, Merk, Tipe, Warna).
   * Pilih **Jenis Kendaraan** (Mobil/Motor) $\rightarrow$ Pilih **Produk Langganan** (misal: 1 Bulan).
   * Pada **Metode Pembayaran**, pilih **`iPaymu (QRIS & Virtual Account)`** $\rightarrow$ pilih **`QRIS`**.
   * Klik **Daftarkan Member**.

2. **Muncul Barcode QRIS & Status Pending:**
   * Sistem akan menampilkan instruksi pembayaran lengkap beserta **Gambar Barcode QRIS**.
   * Status transaksi di database tersimpan sebagai **`PENDING`** (Menunggu Pembayaran).
   * Di daftar member ([transaksi-member.php](transaksi-member.php)), muncul badge kuning **`Menunggu Bayar`**.

3. **Menyelesaikan Pembayaran:**
   * **Mode Live Production:** Pelanggan memindai QRIS menggunakan m-Banking (BCA, Mandiri, BRI, BNI) atau e-Wallet (GoPay, OVO, Dana, ShopeePay) dan menyelesaikan pembayaran.
   * **Mode Sandbox Testing:** Buka **Postman**, jalankan request *Webhook Callback Sukses* ke `http://localhost:81/yeye/optimus/callback-ipaymu.php` (atau klik tombol **Cek Status Pembayaran** di halaman [bayar-member.php](bayar-member.php)).

4. **Status Berubah Menjadi Lunas & Aktif:**
   * Status member otomatis berubah menjadi **`🟢 Aktif / Lunas`**.
   * Kendaraan resmi aktif dan dapat digunakan di palang gerbang parkir.
   * Tombol **Cetak Kwitansi** siap digunakan untuk mencetak tanda bukti pembayaran resmi.

---

---

## 📬 Panduan Pengujian Sandbox (Manual Webhook & Send-Notify Tool)

Karena server lokal (`localhost`) tidak bisa diakses langsung oleh server iPaymu di internet (kecuali menggunakan Ngrok/Tunneling), iPaymu menyediakan tool resmi **Callback Notify Simulation**:

### 🎯 Alur Simulasi Resmi iPaymu (`send-notify` + Postman):

```text
[Input / Perpanjang Member] ──► [Ambil TransactionId di Log] ──► [Buka Tool iPaymu send-notify]
                                                                        │
                                                                 (Pilih Status: Success)
                                                                        │
[Status Database: LUNAS & AKTIF] ◄── [Kirim ke Postman] ◄── [Salin Request Body JSON]
```

#### Langkah-langkah Praktis:
1. **Daftarkan Member / Perpanjang Member:**
   * Lakukan pendaftaran di [input-baru.php](input-baru.php) atau [perpanjang-member.php](perpanjang-member.php).
   * Ambil nomor **`TransactionId`** dari file `logs/ipaymu.log` (misal: `226593`).
2. **Buka Tool Simulasi iPaymu:**
   * Akses URL: 👉 **[https://sandbox.ipaymu.com/send-notify](https://sandbox.ipaymu.com/send-notify)**
   * **Flag:** Pilih `iPaymu Transaction ID`.
   * **Input ID:** Masukkan nilai `TransactionId` tadi (contoh: `226593`).
   * **Transaction Status:** Pilih **`Success`** (untuk simulasi lunas) atau status lain.
   * Klik tombol **SUBMIT**.
3. **Salin Payload & Kirim ke Postman:**
   * Di sebelah kanan tool iPaymu, salin seluruh teks JSON di kolom **Request Body**.
   * Buka **Postman**:
     * **Method:** `POST`
     * **URL:** `http://localhost:81/yeye/optimus/callback-ipaymu.php`
     * **Body:** `raw` $\rightarrow$ `JSON` $\rightarrow$ **Paste teks JSON tadi**.
     * Klik **Send**.
4. **Hasil Akhir:**
   * Postman merespon `{"status":"success", ...}`.
   * Status member di database otomatis berubah menjadi **`LUNAS`** dan kendaraan langsung **`AKTIF`**.

---

### 1. Cara Import Collection ke Postman:
1. Buka aplikasi **Postman**.
2. Klik tombol **Import** di pojok kiri atas.
3. Pilih file **`d:\laragon\www\yeye\optimus\postman_collection.json`** (atau drag-and-drop file tersebut).
4. Collection **`Optimus Parking - iPaymu Webhook & API Testing`** siap digunakan.

---

### 2. Endpoint Webhook Callback:

* **Method:** `POST`
* **URL:** `http://localhost:81/yeye/optimus/callback-ipaymu.php`

#### A. Contoh Request: Pembayaran Sukses (LUNAS)
* **Headers:** `Content-Type: application/json`
* **Body (`raw -> JSON`):**

```json
{
  "trx_id": 226590,
  "sid": "STK/20260821/6A87D28F478BC",
  "reference_id": "STK/20260821/6A87D28F478BC",
  "status": "berhasil",
  "status_code": 1,
  "sub_total": "300000",
  "total": "300000",
  "amount": "300000",
  "fee": "2100",
  "paid_off": 297900,
  "created_at": "2026-08-21 11:22:41",
  "expired_at": "2026-08-22 11:22:41",
  "paid_at": "2026-08-21 11:23:34",
  "settlement_status": "settled",
  "transaction_status_code": 1,
  "is_escrow": false,
  "system_notes": "Sandbox notify",
  "via": "qris",
  "channel": "qris",
  "payment_no": "",
  "buyer_name": "MEMBER PARKIR",
  "buyer_email": "member@example.com",
  "buyer_phone": "081234567890",
  "additional_info": [],
  "url": "http://localhost:81/yeye/optimus/callback-ipaymu.php"
}
```

#### B. Contoh Request: Format Form URL-Encoded (`x-www-form-urlencoded`)
| Key | Value |
| :--- | :--- |
| `trx_id` | `226590` |
| `reference_id` | `STK/20260821/6A87D28F478BC` |
| `status` | `berhasil` |
| `status_code` | `1` |
| `via` | `qris` |
| `channel` | `mpm` |
| `amount` | `300000` |

---

### 3. Contoh Response API

#### A. Response Sukses (`HTTP 200 OK`):
```json
{
  "status": "success",
  "message": "Payment callback processed successfully for STK/20260821/6A87D28F478BC"
}
```

#### B. Response Status Pending / Acknowledged (`HTTP 200 OK`):
```json
{
  "status": "acknowledged",
  "message": "Callback received with status: pending"
}
```

#### C. Response Error Database (`HTTP 200 OK`):
```json
{
  "status": "error",
  "message": "Database error: [Pesan Error PDO]"
}
```

---

## 📁 Struktur File Penting

```text
optimus/
├── config.php                  # Konfigurasi master database, email & iPaymu
├── input-baru.php              # Form pendaftaran member baru & generate QRIS/VA
├── bayar-member.php            # Halaman detail instruksi bayar & sync status
├── callback-ipaymu.php         # Webhook handler penerima notifikasi otomatis iPaymu
├── transaksi-member.php        # Daftar & pencarian member (filter status bayar)
├── perpanjang-member.php       # Perpanjangan masa aktif member
├── mutasi-kendaraan.php        # Mutasi plat nomor kendaraan
├── cetak-kwitansi.php          # Cetak kwitansi pembayaran
├── schema.sql                  # Skema database master PostgreSQL
├── postman_collection.json     # Postman Collection siap import
├── services/
│   └── IPaymuService.php       # Service class integrasi iPaymu API v2
└── logs/
    └── ipaymu.log              # File audit log request & response iPaymu
```