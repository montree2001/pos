-- Create queue_calls table for voice queue functionality
CREATE TABLE IF NOT EXISTS `queue_calls` (
    `call_id` int(11) NOT NULL AUTO_INCREMENT,
    `order_id` int(11) NOT NULL,
    `queue_number` varchar(20) NOT NULL,
    `called_at` datetime DEFAULT CURRENT_TIMESTAMP,
    `called_by` varchar(100) DEFAULT 'System',
    `notes` text DEFAULT NULL,
    PRIMARY KEY (`call_id`),
    KEY `idx_order_id` (`order_id`),
    KEY `idx_queue_number` (`queue_number`),
    KEY `idx_called_at` (`called_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;