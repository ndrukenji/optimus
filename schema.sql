-- =============================================================================
-- SKEMA DATABASE POSTGRESQL - SISTEM OPTIMUS PARKING MANAGEMENT
-- =============================================================================

-- 1. EXTENSIONS (Opsional jika ingin UUID / pgcrypto)
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

-- =============================================================================
-- TABEL 1: users (Data Pengguna / Operator Sistem)
-- =============================================================================
DROP TABLE IF EXISTS users CASCADE;
CREATE TABLE users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    nama_lengkap VARCHAR(100),
    role VARCHAR(20) DEFAULT 'operator',
    status VARCHAR(20) DEFAULT 'aktif',
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- =============================================================================
-- TABEL 2: jenis_mobil (Master Data Kategori / Jenis Kendaraan)
-- =============================================================================
DROP TABLE IF EXISTS jenis_mobil CASCADE;
CREATE TABLE jenis_mobil (
    id VARCHAR(50) PRIMARY KEY,
    nama VARCHAR(100) NOT NULL,
    keterangan VARCHAR(255)
);

-- =============================================================================
-- TABEL 3: tarif_stiker (Master Data Tarif & Durasi Berlangganan)
-- =============================================================================
DROP TABLE IF EXISTS tarif_stiker CASCADE;
CREATE TABLE tarif_stiker (
    id SERIAL PRIMARY KEY,
    id_mobil VARCHAR(50) NOT NULL,
    jenis_langganan VARCHAR(100) NOT NULL,
    tarif NUMERIC(15, 2) NOT NULL DEFAULT 0,
    last_member INT NOT NULL DEFAULT 30, -- durasi hari (misal 30, 90, 180, 365, atau 99 untuk tahunan)
    tgl_akhir DATE,
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_tarif_jenis_mobil FOREIGN KEY (id_mobil) 
        REFERENCES jenis_mobil(id) ON UPDATE CASCADE ON DELETE CASCADE
);

-- =============================================================================
-- TABEL 4: transaksi_stiker (Data Header Transaksi Member Parkir)
-- =============================================================================
DROP TABLE IF EXISTS transaksi_stiker CASCADE;
CREATE TABLE transaksi_stiker (
    notrans VARCHAR(100) PRIMARY KEY,
    nama VARCHAR(150) NOT NULL,
    alamat TEXT,
    telepon VARCHAR(50),
    no_id VARCHAR(100),
    unit_kerja VARCHAR(150),
    awal TIMESTAMP WITHOUT TIME ZONE NOT NULL,
    akhir TIMESTAMP WITHOUT TIME ZONE NOT NULL,
    harga NUMERIC(15, 2) NOT NULL DEFAULT 0,
    tanggal TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    operator VARCHAR(50),
    jenis_transaksi INT DEFAULT 0, -- 0: Baru, 1: Perpanjangan, 2: Mutasi
    tgl_edited TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    email VARCHAR(150),
    no_induk VARCHAR(100),
    no_kartu VARCHAR(100),
    status_bayar VARCHAR(20) DEFAULT 'LUNAS', -- LUNAS, PENDING, BATAL, EXPIRED
    payment_url TEXT,
    payment_no TEXT, -- Menampung Nomor VA atau String EMVCo QRIS (panjang ~300-500 char)
    payment_channel VARCHAR(100),
    payment_trx_id VARCHAR(100),
    qr_data_uri TEXT
);

-- =============================================================================
-- TABEL 5: detail_transaksi_stiker (Data Detail Kendaraan & Status Member)
-- =============================================================================
DROP TABLE IF EXISTS detail_transaksi_stiker CASCADE;
CREATE TABLE detail_transaksi_stiker (
    id SERIAL PRIMARY KEY,
    notrans VARCHAR(100) NOT NULL,
    nopol VARCHAR(30) NOT NULL,
    jenis_mobil VARCHAR(50),
    merk VARCHAR(100),
    tipe VARCHAR(100),
    tahun VARCHAR(20),
    warna VARCHAR(50),
    jenis_member VARCHAR(100),
    status INT DEFAULT 1, -- 1: Aktif, 0: Non-aktif / Tergantikan Mutasi
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_detail_transaksi FOREIGN KEY (notrans) 
        REFERENCES transaksi_stiker(notrans) ON UPDATE CASCADE ON DELETE CASCADE
);

-- =============================================================================
-- TABEL 6: history_mutasi (Log Riwayat Pergantian No. Polisi Kendaraan)
-- =============================================================================
DROP TABLE IF EXISTS history_mutasi CASCADE;
CREATE TABLE history_mutasi (
    id SERIAL PRIMARY KEY,
    notrans VARCHAR(100) NOT NULL,
    nopol_lama VARCHAR(30) NOT NULL,
    nopol_baru VARCHAR(30) NOT NULL,
    tgl_mutasi TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    user_mutasi VARCHAR(50)
);

-- =============================================================================
-- TABEL 7: ipaymu_logs (Audit Log Lengkap Request & Response Gateway iPaymu)
-- =============================================================================
DROP TABLE IF EXISTS ipaymu_logs CASCADE;
CREATE TABLE ipaymu_logs (
    id SERIAL PRIMARY KEY,
    reference_id VARCHAR(100),
    action_type VARCHAR(50) DEFAULT 'DIRECT_PAYMENT',
    endpoint VARCHAR(255),
    request_payload TEXT,
    response_payload TEXT,
    http_code INT,
    status VARCHAR(50),
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- =============================================================================
-- VIEW: mergetransaksistikerdetail (View Penggabungan Header & Detail)
-- Digunakan untuk validasi nopol dan lookup transaksi member
-- =============================================================================
CREATE OR REPLACE VIEW mergetransaksistikerdetail AS
SELECT 
    t.notrans,
    t.nama,
    t.alamat,
    t.telepon,
    t.no_id,
    t.unit_kerja,
    t.awal,
    t.akhir,
    t.harga,
    t.tanggal,
    t.operator,
    t.jenis_transaksi,
    t.tgl_edited,
    t.email,
    t.no_induk,
    t.no_kartu,
    t.status_bayar,
    t.payment_url,
    t.payment_no,
    t.payment_channel,
    t.payment_trx_id,
    t.qr_data_uri,
    d.id AS detail_id,
    d.nopol,
    d.jenis_mobil,
    d.merk,
    d.tipe,
    d.tahun,
    d.warna,
    d.jenis_member,
    d.status AS status_kendaraan
FROM transaksi_stiker t
LEFT JOIN detail_transaksi_stiker d ON t.notrans = d.notrans;

-- =============================================================================
-- INDEXES UNTUK PERFORMA QUERY CEPAT
-- =============================================================================
CREATE INDEX idx_detail_nopol ON detail_transaksi_stiker (nopol);
CREATE INDEX idx_detail_status ON detail_transaksi_stiker (status);
CREATE INDEX idx_transaksi_akhir ON transaksi_stiker (akhir);
CREATE INDEX idx_transaksi_operator ON transaksi_stiker (operator);

-- =============================================================================
-- DATA AWAL / SEED DATA
-- =============================================================================

-- 1. Seed Users (Password: admin)
INSERT INTO users (username, password, nama_lengkap, role, status) VALUES
('admin', 'admin', 'Administrator Pusat', 'admin', 'aktif'),
('operator1', 'operator1', 'Operator Gerbang 1', 'operator', 'aktif')
ON CONFLICT (username) DO NOTHING;

-- 2. Seed Jenis Mobil (Mendukung kode string & numerik untuk kompatibilitas form)
INSERT INTO jenis_mobil (id, nama, keterangan) VALUES
('1', 'MOBIL (Roda 4)', 'Kendaraan roda 4 pribadi/operasional'),
('2', 'MOTOR (Roda 2)', 'Kendaraan roda 2'),
('3', 'TRUK / BOX', 'Kendaraan angkut / niaga'),
('4', 'BUS', 'Bus penumpang')
ON CONFLICT (id) DO UPDATE SET nama = EXCLUDED.nama;

-- 3. Seed Tarif Stiker / Paket Langganan Lengkap
INSERT INTO tarif_stiker (id_mobil, jenis_langganan, tarif, last_member) VALUES
-- MOBIL (id: '1')
('1', '1 BULAN (30 HARI)', 150000.00, 30),
('1', '2 BULAN (60 HARI)', 280000.00, 60),
('1', '3 BULAN / TRIWULAN (90 HARI)', 400000.00, 90),
('1', '6 BULAN / SEMESTER (180 HARI)', 750000.00, 180),
('1', '1 TAHUN / TAHUNAN (365 HARI)', 1400000.00, 99),

-- MOTOR (id: '2')
('2', '1 BULAN (30 HARI)', 50000.00, 30),
('2', '2 BULAN (60 HARI)', 95000.00, 60),
('2', '3 BULAN / TRIWULAN (90 HARI)', 135000.00, 90),
('2', '6 BULAN / SEMESTER (180 HARI)', 250000.00, 180),
('2', '1 TAHUN / TAHUNAN (365 HARI)', 480000.00, 99),

-- TRUK / BOX (id: '3')
('3', '1 BULAN (30 HARI)', 250000.00, 30),
('3', '2 BULAN (60 HARI)', 480000.00, 60),
('3', '3 BULAN / TRIWULAN (90 HARI)', 700000.00, 90),
('3', '6 BULAN / SEMESTER (180 HARI)', 1350000.00, 180),
('3', '1 TAHUN / TAHUNAN (365 HARI)', 2500000.00, 99),

-- BUS (id: '4')
('4', '1 BULAN (30 HARI)', 300000.00, 30),
('4', '2 BULAN (60 HARI)', 580000.00, 60),
('4', '3 BULAN / TRIWULAN (90 HARI)', 850000.00, 90),
('4', '6 BULAN / SEMESTER (180 HARI)', 1600000.00, 180),
('4', '1 TAHUN / TAHUNAN (365 HARI)', 3000000.00, 99);

-- 4. Contoh Data Dummy Transaksi Stiker (Untuk pengujian modul perpanjangan & mutasi)
INSERT INTO transaksi_stiker (notrans, nama, alamat, telepon, no_id, unit_kerja, awal, akhir, harga, tanggal, operator, jenis_transaksi, email, no_induk, no_kartu) VALUES
('STK/20260820/DEMO01', 'BUDI SANTOSO', 'JL. SUDIRMAN NO. 45 JAKARTA', '081234567890', 'MEM-001', 'DIVISI IT', '2026-08-01 00:00:00', '2026-08-31 00:00:00', 150000.00, NOW(), 'admin', 0, 'budi.santoso@example.com', 'KARTU-1001', 'KARTU-1001'),
('STK/20260820/DEMO02', 'SITI RAHMAWATI', 'JL. GATOT SUBROTO NO. 12', '085678901234', 'MEM-002', 'DIVISI KEUANGAN', '2026-07-01 00:00:00', '2026-07-31 00:00:00', 50000.00, NOW(), 'admin', 0, 'siti.rahma@example.com', 'KARTU-1002', 'KARTU-1002')
ON CONFLICT (notrans) DO NOTHING;

INSERT INTO detail_transaksi_stiker (notrans, nopol, jenis_mobil, merk, tipe, tahun, warna, jenis_member, status) VALUES
('STK/20260820/DEMO01', 'B 1234 ABC', '1', 'TOYOTA', 'AVANZA', '2022', 'HITAM', 'BULANAN (30 HARI)', 1),
('STK/20260820/DEMO02', 'B 5678 XYZ', '2', 'HONDA', 'VARIO', '2023', 'PUTIH', 'BULANAN (30 HARI)', 1)
ON CONFLICT DO NOTHING;
