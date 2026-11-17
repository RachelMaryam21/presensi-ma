-- Sistem Informasi Presensi Biometrik Berbasis Lokasi - MA Nurul Jadid
-- Versi BERISI data awal (pengguna, perangkat, lokasi). Tabel presensi sengaja KOSONG.
-- Impor: mysql -u root -p < database_berisi.sql
CREATE DATABASE IF NOT EXISTS presensi_ma CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE presensi_ma;

DROP TABLE IF EXISTS koreksi_presensi;
DROP TABLE IF EXISTS presensi;
DROP TABLE IF EXISTS perangkat;
DROP TABLE IF EXISTS lokasi;
DROP TABLE IF EXISTS pengguna;

CREATE TABLE pengguna (
  id_pengguna      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nip              VARCHAR(30)  NOT NULL UNIQUE,
  nama             VARCHAR(100) NOT NULL,
  kata_sandi_hash  VARCHAR(255) NOT NULL,
  peran            ENUM('guru','tendik','admin','kepala') NOT NULL,
  aktif            TINYINT(1)   NOT NULL DEFAULT 1,
  dibuat_pada      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Satu pengguna dapat punya banyak perangkat, tetapi hanya satu berstatus 'aktif'.
CREATE TABLE perangkat (
  id_perangkat        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_pengguna         INT UNSIGNED NOT NULL,
  identitas_perangkat VARCHAR(64)  NOT NULL,          -- UUID dari perangkat
  nama_perangkat      VARCHAR(100) NOT NULL DEFAULT 'Perangkat Android',
  status_persetujuan  ENUM('aktif','menunggu','ditolak','nonaktif') NOT NULL,
  credential_id       VARCHAR(512) NULL,              -- WebAuthn (mode webauthn)
  public_key          TEXT NULL,                      -- kunci publik (PEM), bukan data sidik jari
  dibuat_pada         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_pengguna (id_pengguna),
  KEY idx_identitas (identitas_perangkat),
  CONSTRAINT fk_perangkat_pengguna FOREIGN KEY (id_pengguna) REFERENCES pengguna(id_pengguna) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE lokasi (
  id_lokasi    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nama_lokasi  VARCHAR(100)  NOT NULL,
  latitude     DECIMAL(10,7) NOT NULL,
  longitude    DECIMAL(10,7) NOT NULL,
  radius_meter INT UNSIGNED  NOT NULL DEFAULT 100,
  aktif        TINYINT(1)    NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE presensi (
  id_presensi      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_pengguna      INT UNSIGNED NOT NULL,
  id_lokasi        INT UNSIGNED NOT NULL,
  tanggal          DATE NOT NULL,
  jam_masuk        TIME NULL,
  jam_pulang       TIME NULL,
  koordinat_masuk  VARCHAR(40) NULL,
  koordinat_pulang VARCHAR(40) NULL,
  jarak_masuk      DECIMAL(8,1) NULL,
  jarak_pulang     DECIMAL(8,1) NULL,
  status           ENUM('tepat_waktu','terlambat','pulang_awal') NOT NULL,
  UNIQUE KEY uq_pengguna_tanggal (id_pengguna, tanggal),   -- menolak presensi ganda (KF-07)
  KEY idx_tanggal (tanggal),
  CONSTRAINT fk_presensi_pengguna FOREIGN KEY (id_pengguna) REFERENCES pengguna(id_pengguna) ON DELETE CASCADE,
  CONSTRAINT fk_presensi_lokasi   FOREIGN KEY (id_lokasi)   REFERENCES lokasi(id_lokasi)
) ENGINE=InnoDB;

-- Jejak audit koreksi data presensi oleh Admin (KF-14)
CREATE TABLE koreksi_presensi (
  id_koreksi    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_presensi   INT UNSIGNED NOT NULL,
  id_admin      INT UNSIGNED NOT NULL,
  alasan        VARCHAR(255) NOT NULL,
  data_sebelum  VARCHAR(255) NULL,
  data_sesudah  VARCHAR(255) NOT NULL,
  dibuat_pada   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_koreksi_presensi FOREIGN KEY (id_presensi) REFERENCES presensi(id_presensi) ON DELETE CASCADE,
  CONSTRAINT fk_koreksi_admin    FOREIGN KEY (id_admin)    REFERENCES pengguna(id_pengguna)
) ENGINE=InnoDB;

-- ===== DATA AWAL =====
-- Titik pusat: Fasilkom Unsri Palembang (2°59'04.9"S 104°43'57.1"E). Ubah kapan saja lewat menu Lokasi.
INSERT INTO lokasi (nama_lokasi, latitude, longitude, radius_meter) VALUES
 ('Titik pusat Fasilkom Unsri Palembang', -2.9846944, 104.7325278, 100);

-- Kata sandi SEMUA akun contoh: password123  (GANTI setelah dipakai sungguhan)
INSERT INTO pengguna (nip, nama, kata_sandi_hash, peran) VALUES
 ('199203142018', 'Rudi Hartono', '$2y$10$dj9.CXvj6fo97vW30k8U2OimV/ZGuUyNw7M8Hln3jdzwKaBnT24iu', 'admin'),
 ('197809202005', 'Nur Aini, S.Pd', '$2y$10$dj9.CXvj6fo97vW30k8U2OimV/ZGuUyNw7M8Hln3jdzwKaBnT24iu', 'kepala'),
 ('198504122010', 'Ahmad Fauzi', '$2y$10$dj9.CXvj6fo97vW30k8U2OimV/ZGuUyNw7M8Hln3jdzwKaBnT24iu', 'guru'),
 ('199011052015', 'Siti Aminah', '$2y$10$dj9.CXvj6fo97vW30k8U2OimV/ZGuUyNw7M8Hln3jdzwKaBnT24iu', 'tendik'),
 ('199512122020', 'Dewi Lestari', '$2y$10$dj9.CXvj6fo97vW30k8U2OimV/ZGuUyNw7M8Hln3jdzwKaBnT24iu', 'tendik'),
 ('198207152008', 'Moh. Hasan, S.Pd.I', '$2y$10$dj9.CXvj6fo97vW30k8U2OimV/ZGuUyNw7M8Hln3jdzwKaBnT24iu', 'guru'),
 ('198803222012', 'Fatimah Azzahra, S.Pd', '$2y$10$dj9.CXvj6fo97vW30k8U2OimV/ZGuUyNw7M8Hln3jdzwKaBnT24iu', 'guru'),
 ('199106302016', 'Abdul Rahman, M.Pd', '$2y$10$dj9.CXvj6fo97vW30k8U2OimV/ZGuUyNw7M8Hln3jdzwKaBnT24iu', 'guru'),
 ('198512102011', 'Nurul Hidayati, S.Pd', '$2y$10$dj9.CXvj6fo97vW30k8U2OimV/ZGuUyNw7M8Hln3jdzwKaBnT24iu', 'guru'),
 ('199308182017', 'Zainal Arifin, S.Ag', '$2y$10$dj9.CXvj6fo97vW30k8U2OimV/ZGuUyNw7M8Hln3jdzwKaBnT24iu', 'guru'),
 ('198009252007', 'Khoirul Anam, S.Pd', '$2y$10$dj9.CXvj6fo97vW30k8U2OimV/ZGuUyNw7M8Hln3jdzwKaBnT24iu', 'guru'),
 ('199404052019', 'Lailatul Qomariyah, S.Pd', '$2y$10$dj9.CXvj6fo97vW30k8U2OimV/ZGuUyNw7M8Hln3jdzwKaBnT24iu', 'guru'),
 ('199710122021', 'Ahmad Syafii', '$2y$10$dj9.CXvj6fo97vW30k8U2OimV/ZGuUyNw7M8Hln3jdzwKaBnT24iu', 'tendik'),
 ('199601282022', 'Mutmainnah', '$2y$10$dj9.CXvj6fo97vW30k8U2OimV/ZGuUyNw7M8Hln3jdzwKaBnT24iu', 'tendik'),
 ('198906142014', 'Imam Ghozali, S.Kom', '$2y$10$dj9.CXvj6fo97vW30k8U2OimV/ZGuUyNw7M8Hln3jdzwKaBnT24iu', 'tendik');

-- Contoh permintaan pergantian perangkat (tampil di menu Pengguna & Perangkat -> Setujui / Tolak).
-- Perangkat lain terikat otomatis saat pengguna login pertama di aplikasi mobile.
INSERT INTO perangkat (id_pengguna, identitas_perangkat, nama_perangkat, status_persetujuan)
SELECT id_pengguna, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'Samsung A54', 'menunggu' FROM pengguna WHERE nip = '199011052015';

-- Tabel presensi & koreksi_presensi sengaja dibiarkan kosong (riwayat absen dimulai dari nol).
