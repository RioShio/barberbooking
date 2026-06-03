-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Jun 03, 2026 at 04:19 AM
-- Server version: 8.0.30
-- PHP Version: 8.1.10

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `barbershop_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `bookings`
--

CREATE TABLE `bookings` (
  `id` int NOT NULL,
  `kode` varchar(10) DEFAULT NULL,
  `nama` varchar(100) NOT NULL,
  `telepon` varchar(20) DEFAULT NULL,
  `potongan` varchar(50) NOT NULL,
  `kapster` varchar(50) DEFAULT 'Any',
  `tanggal` date NOT NULL,
  `waktu` time NOT NULL,
  `produk` text,
  `catatan` text,
  `status` enum('pending','confirmed','in_progress','done','cancelled') DEFAULT 'pending',
  `total_harga` int DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `kapsters`
--

CREATE TABLE `kapsters` (
  `id` int NOT NULL,
  `nama` varchar(100) NOT NULL,
  `spesialisasi` varchar(200) DEFAULT NULL,
  `rating` decimal(2,1) DEFAULT '5.0',
  `foto_inisial` varchar(5) DEFAULT NULL,
  `aktif` tinyint DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `kapsters`
--

INSERT INTO `kapsters` (`id`, `nama`, `spesialisasi`, `rating`, `foto_inisial`, `aktif`) VALUES
(1, 'Rizki Andika', 'Wolf Cut, Curly, Fade', 4.9, 'RA', 1),
(2, 'Dimas Pratama', 'French Crop, Classic, Mullet', 4.8, 'DP', 1),
(3, 'Fajar Nugroho', 'Fade, Undercut, Pompadour', 4.7, 'FN', 1);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `bookings`
--
ALTER TABLE `bookings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `kode` (`kode`);

--
-- Indexes for table `kapsters`
--
ALTER TABLE `kapsters`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `bookings`
--
ALTER TABLE `bookings`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `kapsters`
--
ALTER TABLE `kapsters`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
