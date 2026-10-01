-- Phase 8（自動テスト）用のデータベース。
-- テストで RefreshDatabase を使うとテーブルが作り直されるため、
-- 手動検証用の laravel DB とは分けておく。
-- ※ このファイルは db ボリュームの初回作成時にしか実行されない。
CREATE DATABASE IF NOT EXISTS laravel_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON laravel_testing.* TO 'laravel'@'%';
FLUSH PRIVILEGES;
