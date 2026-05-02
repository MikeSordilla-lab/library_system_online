-- =============================================================================
-- Library System Online — User Seed Data
-- =============================================================================
-- Generates 80 users: 3 admin + 7 librarian + 70 borrower
-- All passwords: admin123
-- bcrypt cost 12, pre-verified, not suspended
-- Excludes existing admin@library.local (handled by infinityfree-import.sql)
-- =============================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- 3 Admin users
-- ---------------------------------------------------------------------------
INSERT INTO `Users` (`full_name`, `email`, `password_hash`, `role`, `is_superadmin`, `is_verified`, `is_suspended`, `birthdate`, `address`, `phone`, `created_at`) VALUES
('Maria Clara Santos',    'mcsantos@library.local',    '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'admin', 1, 1, 0, '1985-03-15', '123 Rizal Ave, Makati City',        '+63 912 345 6789', '2025-01-10 08:30:00'),
('Jose Rizal Cruz',       'jrcruz@library.local',      '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'admin', 0, 1, 0, '1988-07-22', '456 Bonifacio St, Quezon City',     '+63 917 234 5678', '2025-01-15 09:00:00'),
('Andrea Mae Villanueva', 'amvillanueva@library.local', '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'admin', 0, 1, 0, '1990-11-08', '789 Mabini Blvd, Pasig City',       '+63 918 876 5432', '2025-02-01 10:15:00');

-- ---------------------------------------------------------------------------
-- 7 Librarian users
-- ---------------------------------------------------------------------------
INSERT INTO `Users` (`full_name`, `email`, `password_hash`, `role`, `is_verified`, `is_suspended`, `birthdate`, `address`, `phone`, `created_at`) VALUES
('Juan Dela Cruz',        'jdelacruz@library.local',   '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'librarian', 1, 0, '1992-01-20', '101 Luna St, Caloocan City',         '+63 919 111 2233', '2025-01-12 08:00:00'),
('Elena Rodriguez',       'erodriguez@library.local',  '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'librarian', 1, 0, '1993-04-10', '202 Aguinaldo Rd, Manila',           '+63 920 222 3344', '2025-01-20 08:30:00'),
('Pedro Sarmiento',       'psarmiento@library.local',  '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'librarian', 1, 0, '1989-09-05', '303 Katipunan Ave, Quezon City',     '+63 921 333 4455', '2025-01-25 09:00:00'),
('Sofia Reyes',           'sreyes@library.local',      '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'librarian', 1, 0, '1995-06-18', '404 Taft Ave, Pasay City',           '+63 922 444 5566', '2025-02-05 09:30:00'),
('Miguel Fernandez',      'mfernandez@library.local',  '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'librarian', 1, 0, '1991-12-30', '505 Shaw Blvd, Mandaluyong City',    '+63 923 555 6677', '2025-02-10 10:00:00'),
('Carmela Torres',        'ctorres@library.local',     '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'librarian', 1, 0, '1994-08-14', '606 Ortigas Ave, San Juan City',     '+63 924 666 7788', '2025-02-15 10:30:00'),
('Rafael Gonzales',       'rgonzales@library.local',   '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'librarian', 1, 0, '1987-02-28', '707 EDSA, Cubao, Quezon City',       '+63 925 777 8899', '2025-02-20 11:00:00');

-- ---------------------------------------------------------------------------
-- 70 Borrower users (batches of 10 for readability)
-- ---------------------------------------------------------------------------

-- Batch 1 (borrowers 1-10)
INSERT INTO `Users` (`full_name`, `email`, `password_hash`, `role`, `is_verified`, `is_suspended`, `birthdate`, `address`, `phone`, `created_at`) VALUES
('Angelo Bautista',       'abautista@library.local',   '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '2000-05-12', '1 A. Mabini St, Manila',             '+63 926 101 0001', '2025-03-01 08:00:00'),
('Bea Marie Castillo',    'bcastillo@library.local',   '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '2001-08-23', '2 B. Luna St, Quezon City',          '+63 926 102 0002', '2025-03-01 08:05:00'),
('Carlito Domingo',       'cdomingo@library.local',    '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '1999-11-30', '3 C. Rizal Ave, Makati',             '+63 926 103 0003', '2025-03-01 08:10:00'),
('Diana Rose Evangelista','devangelista@library.local', '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '2002-02-14', '4 D. Bonifacio St, Pasig',          '+63 926 104 0004', '2025-03-01 08:15:00'),
('Emilio Fernando',       'efernando@library.local',   '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '1998-07-07', '5 E. Aguinaldo Rd, Cavite',          '+63 926 105 0005', '2025-03-01 08:20:00'),
('Felicity Garcia',       'fgarcia@library.local',     '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '2003-04-19', '6 F. Katipunan Ave, QC',            '+63 926 106 0006', '2025-03-01 08:25:00'),
('Gabriel Hernandez',     'ghernandez@library.local',  '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '2000-09-03', '7 G. Taft Ave, Pasay',              '+63 926 107 0007', '2025-03-02 08:00:00'),
('Hannah Isabelle Lopez', 'hlopez@library.local',      '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '2001-12-25', '8 H. Shaw Blvd, Mandaluyong',       '+63 926 108 0008', '2025-03-02 08:10:00'),
('Ian Christopher Manalo','imanalo@library.local',     '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '1999-06-15', '9 I. Ortigas Ave, San Juan',        '+63 926 109 0009', '2025-03-02 08:20:00'),
('Jasmine Nicole Ocampo', 'jocampo@library.local',     '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '2002-10-08', '10 J. EDSA, Cubao, QC',             '+63 926 110 0010', '2025-03-02 08:30:00');

-- Batch 2 (borrowers 11-20)
INSERT INTO `Users` (`full_name`, `email`, `password_hash`, `role`, `is_verified`, `is_suspended`, `birthdate`, `address`, `phone`, `created_at`) VALUES
('Kevin Patrick Reyes',   'kreyes@library.local',      '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '2001-01-05', '11 K. Marcos Hwy, Marikina',         '+63 927 201 0011', '2025-03-03 08:00:00'),
('Larissa Mae Santos',    'lsantos@library.local',     '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '2000-03-18', '12 L. Commonwealth Ave, QC',         '+63 927 202 0012', '2025-03-03 08:10:00'),
('Marco Antonio Tan',     'mtan@library.local',        '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '1998-08-21', '13 M. Quirino Ave, Paranaque',       '+63 927 203 0013', '2025-03-03 08:20:00'),
('Natalie Claire Uy',     'nuy@library.local',         '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '2003-05-30', '14 N. Aurora Blvd, Cubao, QC',       '+63 927 204 0014', '2025-03-03 08:30:00'),
('Oscar Luis Villanueva', 'ovillanueva@library.local', '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '1997-11-11', '15 O. Gil Puyat Ave, Makati',        '+63 927 205 0015', '2025-03-04 09:00:00'),
('Patricia Anne Wong',    'pwong@library.local',       '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '2001-07-09', '16 P. Timog Ave, QC',                '+63 927 206 0016', '2025-03-04 09:10:00'),
('Quentin James Yap',     'qyap@library.local',        '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '1999-02-28', '17 Q. Sucat Rd, Paranaque',          '+63 927 207 0017', '2025-03-04 09:20:00'),
('Rachel Ann Zulueta',    'rzulueta@library.local',    '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '2002-04-16', '18 R. Alabang-Zapote Rd, Muntinlupa','+63 927 208 0018', '2025-03-04 09:30:00'),
('Samuel David Abella',   'sabella@library.local',     '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '2000-06-22', '19 S. Quirino Hwy, Novaliches',      '+63 927 209 0019', '2025-03-05 08:00:00'),
('Therese Joy Bernardo',  'tbernardo@library.local',   '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '2001-09-14', '20 T. Regalado Ave, Fairview, QC',   '+63 927 210 0020', '2025-03-05 08:15:00');

-- Batch 3 (borrowers 21-30)
INSERT INTO `Users` (`full_name`, `email`, `password_hash`, `role`, `is_verified`, `is_suspended`, `birthdate`, `address`, `phone`, `created_at`) VALUES
('Ulysses Grant Cruz',    'ucruz@library.local',       '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '1998-12-01', '21 U. North Ave, QC',                '+63 928 301 0021', '2025-03-05 08:30:00'),
('Vanessa Marie Diaz',    'vdiaz@library.local',       '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '2003-03-03', '22 V. Visayas Ave, QC',              '+63 928 302 0022', '2025-03-05 08:45:00'),
('William Anthony Esguerra','wesguerra@library.local', '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '2000-10-27', '23 W. Mindanao Ave, QC',             '+63 928 303 0023', '2025-03-06 09:00:00'),
('Xandra Bianca Flores',  'xflores@library.local',     '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '2001-01-31', '24 X. Congressional Ave, QC',        '+63 928 304 0024', '2025-03-06 09:15:00'),
('Ysabel Grace Gomez',    'ygomez@library.local',      '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '1999-07-17', '25 Y. Tandang Sora Ave, QC',         '+63 928 305 0025', '2025-03-06 09:30:00'),
('Zachary Paul Hernandez', 'zhernandez@library.local', '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '2002-08-04', '26 Z. Kalayaan Ave, Makati',         '+63 928 306 0026', '2025-03-06 09:45:00'),
('Alyssa Marie Ignacio',  'aignacio@library.local',    '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '2001-05-20', '27 A. JP Rizal St, Makati',          '+63 928 307 0027', '2025-03-07 10:00:00'),
('Benedict John Javier',  'bjavier@library.local',     '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '1998-11-08', '28 B. Pasong Tamo, Makati',          '+63 928 308 0028', '2025-03-07 10:15:00'),
('Catherine Joy King',    'cking@library.local',       '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '2000-04-12', '29 C. Buendia Ave, Makati',          '+63 928 309 0029', '2025-03-07 10:30:00'),
('Dominic Ryan Lazaro',   'dlazaro@library.local',     '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '2002-02-19', '30 D. Arnaiz Ave, Makati',           '+63 928 310 0030', '2025-03-07 10:45:00');

-- Batch 4 (borrowers 31-40)
INSERT INTO `Users` (`full_name`, `email`, `password_hash`, `role`, `is_verified`, `is_suspended`, `birthdate`, `address`, `phone`, `created_at`) VALUES
('Erika Mae Mendoza',     'emendoza@library.local',    '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '2001-12-10', '31 E. Paseo de Roxas, Makati',       '+63 929 401 0031', '2025-03-08 08:00:00'),
('Francis Noel Navarro',  'fnavarro@library.local',    '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '1999-06-25', '32 F. Ayala Ave, Makati',            '+63 929 402 0032', '2025-03-08 08:15:00'),
('Gloria Mae Ortega',     'gortega@library.local',     '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '2003-09-30', '33 G. Makati Ave, Makati',           '+63 929 403 0033', '2025-03-08 08:30:00'),
('Harold Joseph Pascual', 'hpascual@library.local',    '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '2000-03-05', '34 H. Valero St, Salcedo Village',   '+63 929 404 0034', '2025-03-08 08:45:00'),
('Isabel Fiona Quinto',   'iquinto@library.local',     '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '2002-07-18', '35 I. Legaspi St, Legaspi Village',  '+63 929 405 0035', '2025-03-09 09:00:00'),
('Julian Carlo Ramos',    'jramos@library.local',      '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '1998-10-13', '36 J. Jupiter St, Bel-Air, Makati',  '+63 929 406 0036', '2025-03-09 09:15:00'),
('Kristine Louise Salazar','ksalazar@library.local',   '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '2001-01-28', '37 K. Rockwell Dr, Makati',          '+63 929 407 0037', '2025-03-09 09:30:00'),
('Lorenzo Miguel Torres', 'ltorres@library.local',     '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '2000-08-15', '38 L. BGC, Taguig',                  '+63 929 408 0038', '2025-03-09 09:45:00'),
('Monica Anne Umali',     'mumali@library.local',      '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '2003-04-22', '39 M. McKinley Rd, Taguig',          '+63 929 409 0039', '2025-03-10 10:00:00'),
('Nathaniel Vince Vega',  'nvega@library.local',       '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '1999-11-05', '40 N. C5 Rd, Taguig',                '+63 929 410 0040', '2025-03-10 10:15:00');

-- Batch 5 (borrowers 41-50)
INSERT INTO `Users` (`full_name`, `email`, `password_hash`, `role`, `is_verified`, `is_suspended`, `birthdate`, `address`, `phone`, `created_at`) VALUES
('Odessa Pearl Agustin',  'oagustin@library.local',    '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '2001-06-14', '41 O. Acacia Estates, Taguig',       '+63 930 501 0041', '2025-03-10 10:30:00'),
('Paolo Miguel Basa',     'pbasa@library.local',       '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '2000-02-09', '42 P. Bayani Rd, Taguig',            '+63 930 502 0042', '2025-03-10 10:45:00'),
('Queenie Rose Canlas',   'qcanlas@library.local',     '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '2002-09-26', '43 Q. Cayetano Blvd, Taguig',        '+63 930 503 0043', '2025-03-11 08:00:00'),
('Ronnie Glenn Del Mundo','rdelmundo@library.local',   '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '1998-05-03', '44 R. DBP Ave, Taguig',              '+63 930 504 0044', '2025-03-11 08:15:00'),
('Sarah Jane Enrile',     'senrile@library.local',     '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '2003-12-31', '45 S. Eastwood Ave, Libis, QC',      '+63 930 505 0045', '2025-03-11 08:30:00'),
('Tristan Kyle Fabian',   'tfabian@library.local',     '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '2000-10-20', '46 T. E. Rodriguez Ave, QC',         '+63 930 506 0046', '2025-03-11 08:45:00'),
('Ursula Nicole Galang',  'ugalang@library.local',     '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '2001-03-08', '47 U. Gilmore Ave, QC',              '+63 930 507 0047', '2025-03-12 09:00:00'),
('Victor Allan Hipolito', 'vhipolito@library.local',   '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '1999-08-17', '48 V. Hemady St, QC',                '+63 930 508 0048', '2025-03-12 09:15:00'),
('Wendy Patricia Ilagan', 'wilagan@library.local',     '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '2002-01-22', '49 W. Ilang-Ilang St, QC',           '+63 930 509 0049', '2025-03-12 09:30:00'),
('Xavier Dominic Jacinto','xjacinto@library.local',    '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '2000-07-11', '50 X. J. Abad Santos, San Juan',     '+63 930 510 0050', '2025-03-12 09:45:00');

-- Batch 6 (borrowers 51-60)
INSERT INTO `Users` (`full_name`, `email`, `password_hash`, `role`, `is_verified`, `is_suspended`, `birthdate`, `address`, `phone`, `created_at`) VALUES
('Yvonne Claire Kabigting','ykabigting@library.local', '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '2001-09-09', '51 Y. Kamuning Rd, QC',              '+63 931 601 0051', '2025-03-13 10:00:00'),
('Zoren Matthew Lacson',  'zlacson@library.local',     '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '1998-04-27', '52 Z. Lantana St, Cubao, QC',        '+63 931 602 0052', '2025-03-13 10:15:00'),
('Aira Bianca Macaraeg',  'amacaraeg@library.local',   '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '2002-06-05', '53 A. Maginhawa St, UP Village, QC', '+63 931 603 0053', '2025-03-13 10:30:00'),
('Bryan Lester Natividad','bnatividad@library.local',  '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '2000-11-29', '54 B. N. Domingo, San Juan',         '+63 931 604 0054', '2025-03-13 10:45:00'),
('Charlene May Olivarez', 'colivarez@library.local',   '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '2003-02-16', '55 C. Ortigas Ext, Pasig',           '+63 931 605 0055', '2025-03-14 08:00:00'),
('Dexter Ian Pangilinan', 'dpangilinan@library.local', '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '1999-05-08', '56 D. Pioneer St, Pasig',            '+63 931 606 0056', '2025-03-14 08:15:00'),
('Eliza Margaret Querubin','equerubin@library.local',  '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '2001-10-14', '57 E. Quezon Blvd, Pasig',           '+63 931 607 0057', '2025-03-14 08:30:00'),
('Frederick Von Rosario', 'frosario@library.local',    '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '2000-01-03', '58 F. Ruby Rd, Ortigas, Pasig',      '+63 931 608 0058', '2025-03-14 08:45:00'),
('Gina Patricia Suarez',  'gsuarez@library.local',     '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '2002-08-19', '59 G. Sapphire Rd, Ortigas, Pasig',  '+63 931 609 0059', '2025-03-15 09:00:00'),
('Henry James Tuazon',    'htuazon@library.local',     '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '1998-12-24', '60 H. Topaz Rd, Ortigas, Pasig',     '+63 931 610 0060', '2025-03-15 09:15:00');

-- Batch 7 (borrowers 61-70)
INSERT INTO `Users` (`full_name`, `email`, `password_hash`, `role`, `is_verified`, `is_suspended`, `birthdate`, `address`, `phone`, `created_at`) VALUES
('Isabella Grace Untalan', 'iuntalan@library.local',   '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '2001-07-25', '61 I. Emerald Ave, Ortigas, Pasig',  '+63 932 701 0061', '2025-03-15 09:30:00'),
('Jericho Luis Valdez',   'jvaldez@library.local',     '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '2000-04-11', '62 J. Garnet Rd, Ortigas, Pasig',    '+63 932 702 0062', '2025-03-15 09:45:00'),
('Katrina Faye Villarama','kvillarama@library.local',  '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '2003-10-02', '63 K. Amethyst Rd, Ortigas, Pasig',  '+63 932 703 0063', '2025-03-16 10:00:00'),
('Lance Rafael Wagan',    'lwagan@library.local',      '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '1999-03-19', '64 L. Diamond Rd, Ortigas, Pasig',   '+63 932 704 0064', '2025-03-16 10:15:00'),
('Megan Ashley Xavier',   'mxavier@library.local',     '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '2001-11-07', '65 M. Pearl Dr, Ortigas, Pasig',     '+63 932 705 0065', '2025-03-16 10:30:00'),
('Nico Angelo Ylarde',    'nylarde@library.local',     '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '2000-06-30', '66 N. Opal Rd, Ortigas, Pasig',      '+63 932 706 0066', '2025-03-16 10:45:00'),
('Olivia Danielle Zamora','ozamora@library.local',     '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '2002-05-15', '67 O. Jade Dr, Ortigas, Pasig',      '+63 932 707 0067', '2025-03-17 08:00:00'),
('Paolo Enrico Aguilar',  'paguilar@library.local',    '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '1998-09-28', '68 P. Onyx St, Ortigas, Pasig',      '+63 932 708 0068', '2025-03-17 08:15:00'),
('Quinn Alexandra Borja', 'qborja@library.local',      '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '2003-08-13', '69 Q. Ruby Rd, Ortigas, Pasig',      '+63 932 709 0069', '2025-03-17 08:30:00'),
('Renz Marion Castillo',  'rcastillo@library.local',   '$2y$12$sxn2LqOWUQvTidMFipUx9.mBGJniSMTE42GiP31O3SaLRmZwaGwFy', 'borrower', 1, 0, '2000-12-06', '70 R. Garnet St, Ortigas, Pasig',    '+63 932 710 0070', '2025-03-17 08:45:00');

-- =============================================================================
-- Verification query (run to confirm counts)
-- =============================================================================
-- SELECT role, COUNT(*) AS total FROM Users GROUP BY role ORDER BY FIELD(role, 'admin', 'librarian', 'borrower');
-- Expected: admin=3, librarian=7, borrower=70 (80 total, excluding existing bootstrap admin)