-- HouseCall database: schema + default administrator
-- Import with phpMyAdmin (Import tab) or:  mysql -u root -p < database/housecall.sql

CREATE DATABASE IF NOT EXISTS housecall CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE housecall;

-- Users: clients, service providers and administrators share one table
CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role          ENUM('client','provider','admin') NOT NULL,
  username      VARCHAR(20)  NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  name          VARCHAR(50)  NOT NULL,
  surname       VARCHAR(50)  NOT NULL,
  gender        ENUM('Male','Female','Prefer not to say') NOT NULL,
  age           TINYINT UNSIGNED NOT NULL,
  phone         CHAR(10)     NOT NULL,
  status        ENUM('active','suspended') NOT NULL DEFAULT 'active',
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_users_role (role)
) ENGINE=InnoDB;

-- Provider profile: rate shown to clients
CREATE TABLE IF NOT EXISTS provider_profiles (
  user_id     INT UNSIGNED PRIMARY KEY,
  rate_amount DECIMAL(8,2) NULL,
  rate_type   ENUM('per_hour','per_day','per_job') NULL,
  bio         VARCHAR(500) NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Services offered (category keys: gardener, plumber, cleaner, tutor_high_school, tutor_tertiary)
CREATE TABLE IF NOT EXISTS provider_services (
  user_id  INT UNSIGNED NOT NULL,
  category VARCHAR(30)  NOT NULL,
  PRIMARY KEY (user_id, category),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Areas of operation
CREATE TABLE IF NOT EXISTS provider_areas (
  user_id INT UNSIGNED NOT NULL,
  area    VARCHAR(30)  NOT NULL,
  PRIMARY KEY (user_id, area),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Operating times: day_of_week 1 = Monday ... 7 = Sunday
CREATE TABLE IF NOT EXISTS provider_hours (
  user_id     INT UNSIGNED NOT NULL,
  day_of_week TINYINT UNSIGNED NOT NULL,
  start_time  TIME NOT NULL,
  end_time    TIME NOT NULL,
  PRIMARY KEY (user_id, day_of_week),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Provider advertisements (displayed on the client page)
CREATE TABLE IF NOT EXISTS provider_ads (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  provider_id INT UNSIGNED NOT NULL,
  title       VARCHAR(80)  NOT NULL,
  description VARCHAR(300) NULL,
  image_path  VARCHAR(80)  NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (provider_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Bookings (daily). completed = service rendered
CREATE TABLE IF NOT EXISTS bookings (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id    INT UNSIGNED NOT NULL,
  provider_id  INT UNSIGNED NOT NULL,
  category     VARCHAR(30)  NOT NULL,
  area         VARCHAR(30)  NOT NULL,
  booking_date DATE         NOT NULL,
  description  TEXT NULL,
  status       ENUM('pending','confirmed','completed','cancelled') NOT NULL DEFAULT 'pending',
  cancelled_by ENUM('client','provider') NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (client_id)   REFERENCES users(id),
  FOREIGN KEY (provider_id) REFERENCES users(id),
  INDEX idx_provider_day (provider_id, booking_date, status),
  INDEX idx_booking_status (status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS booking_images (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  booking_id INT UNSIGNED NOT NULL,
  image_path VARCHAR(80)  NOT NULL,
  FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Two-way ratings: the client rates the provider AND the provider rates the client
CREATE TABLE IF NOT EXISTS ratings (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  booking_id INT UNSIGNED NOT NULL,
  rater_id   INT UNSIGNED NOT NULL,
  ratee_id   INT UNSIGNED NOT NULL,
  stars      TINYINT UNSIGNED NOT NULL,
  review     VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_one_rating (booking_id, rater_id),
  CONSTRAINT chk_stars CHECK (stars BETWEEN 1 AND 5),
  FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
  FOREIGN KEY (rater_id)   REFERENCES users(id),
  FOREIGN KEY (ratee_id)   REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notifications (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  message    VARCHAR(300) NOT NULL,
  is_read    TINYINT(1)   NOT NULL DEFAULT 0,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_notif_user (user_id, is_read)
) ENGINE=InnoDB;

-- Every login attempt (feeds the "number of logins" report)
CREATE TABLE IF NOT EXISTS login_logs (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED NULL,
  username      VARCHAR(50)  NOT NULL,
  role_selected ENUM('client','provider','admin') NOT NULL,
  success       TINYINT(1)   NOT NULL,
  reason        VARCHAR(40)  NULL,
  ip            VARCHAR(45)  NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_login_user (username, success, created_at)
) ENGINE=InnoDB;

-- Default administrator.  Username: admin   Password: Admin@1234
-- CHANGE THIS PASSWORD after your first login (Administrator > Users > Reset password).
INSERT IGNORE INTO users (role, username, password_hash, name, surname, gender, age, phone)
VALUES ('admin', 'admin', '$2y$10$8QlmQg5toyycRgzcRNquOuAamEQe3rMLJvl8a0xfsNEgN1bS.1XxC', 'System', 'Administrator', 'Prefer not to say', 30, '0123456789');