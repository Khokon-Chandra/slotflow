-- The test suite runs against MySQL, not SQLite in memory: the double-booking
-- guard depends on SELECT … FOR UPDATE, and a suite that runs on a database
-- without the feature under test passes for the wrong reason.
CREATE DATABASE IF NOT EXISTS `slotflow_testing`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

GRANT ALL PRIVILEGES ON `slotflow_testing`.* TO 'root'@'%';
FLUSH PRIVILEGES;
