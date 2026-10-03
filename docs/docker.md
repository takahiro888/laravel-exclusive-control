# Docker 環境の解説

このドキュメントは、本プロジェクトの Docker 構成を「なぜこうなっているのか」まで理解するための資料です。
Phase 1 で作成した環境をベースに説明します。

---

## 1. 全体構成

```
ブラウザ
  │  http://localhost:8080
  ▼
┌──────────────────────── Docker ネットワーク (laravel-exclusive-control_default) ───────────────────────┐
│                                                                                                         │
│  ┌──────────────┐   FastCGI (app:9000)   ┌──────────────────┐   MySQL プロトコル (db:3306)   ┌──────────┐ │
│  │ web (Nginx)  │ ─────────────────────▶ │ app (PHP-FPM)    │ ─────────────────────────────▶ │ db       │ │
│  │ ec-web       │                        │ ec-app / Laravel │                                │ MySQL8.0 │ │
│  └──────────────┘                        └──────────────────┘                                └──────────┘ │
│        ▲ ./src を共有                           ▲ ./src を共有                                  ▲ db-data   │
└────────┼────────────────────────────────────────┼───────────────────────────────────────────────┼─────────┘
         │                                        │                                               │
     ホストの ./src  ←── エディタで編集すると即座に両コンテナに反映 ──┘                    名前付きボリューム
                                                                                      (DB の実データ)
ホストから DB に直接接続: 127.0.0.1:33060 → db:3306
```

| サービス | コンテナ名 | イメージ | 役割 |
|---|---|---|---|
| `web` | ec-web | nginx:1.27-alpine | HTTP を受け、静的ファイルは自分で返し、PHP は app に転送する |
| `app` | ec-app | 自作 (docker/php/Dockerfile) | PHP-FPM で Laravel を実行する。artisan / composer もここで実行する |
| `db` | ec-db | mysql:8.0 | 予約データを保存する。排他制御の主役 |

### なぜ `php artisan serve` ではなく Nginx + PHP-FPM なのか

`php artisan serve` は PHP 組み込みサーバーで、基本的に **1リクエストずつ順番に処理** します。
これでは「ブラウザ A と B が同時に更新した」状況が本当の意味で並行に起きません。

PHP-FPM は複数のワーカープロセスを持ち、**リクエストごとに別プロセス・別 DB 接続** で処理します。
排他制御の検証では「2つのトランザクションが同時に走る」ことが前提なので、本番に近いこの構成にしています。

---

## 2. ファイルごとの解説

```
.
├── .env                         # Compose 用の変数 (UID/GID)
├── docker-compose.yml           # 3コンテナの定義
├── docker/
│   ├── php/
│   │   ├── Dockerfile           # app コンテナのイメージ
│   │   └── php.ini              # PHP の設定
│   ├── nginx/
│   │   └── default.conf         # Nginx の設定
│   └── mysql/
│       ├── my.cnf               # MySQL の設定
│       └── init/
│           └── 01_create_testing_db.sql  # 初回起動時だけ実行される SQL
└── src/                         # Laravel 本体
    └── .env                     # Laravel 用の環境変数 (DB 接続先など)
```

### 2-1. docker-compose.yml

複数コンテナを「1つのアプリ」としてまとめて定義するファイルです。重要な設定を抜粋して説明します。

#### build と image の違い

```yaml
app:
  build:
    context: ./docker/php   # このディレクトリの Dockerfile からイメージを作る
web:
  image: nginx:1.27-alpine  # Docker Hub の既製イメージをそのまま使う
```

Nginx と MySQL は設定ファイルを差し込むだけで済むので既製イメージを使います。
PHP は拡張モジュール (pdo_mysql) や Composer を追加する必要があるので、自分でイメージをビルドします。

#### volumes: バインドマウントと名前付きボリューム

```yaml
app:
  volumes:
    - ./src:/var/www/html            # バインドマウント
db:
  volumes:
    - db-data:/var/lib/mysql         # 名前付きボリューム
    - ./docker/mysql/my.cnf:/etc/mysql/conf.d/my.cnf:ro
```

| 種類 | 書き方 | 実体の場所 | 用途 |
|---|---|---|---|
| バインドマウント | `./src:/var/www/html` | ホストのディレクトリそのもの | ソースコード、設定ファイル。ホストで編集した内容が即座にコンテナに見える |
| 名前付きボリューム | `db-data:/var/lib/mysql` | Docker が管理する領域 | DB のデータ。コンテナを作り直しても消えない |

`:ro` は read only の意味です。コンテナ側から設定ファイルを書き換えられないようにしています。

**ポイント:** コンテナは使い捨てが前提です。`docker compose down` でコンテナは消えますが、
名前付きボリュームに入っている DB のデータは残ります。データまで消したい場合は `docker compose down -v` を使います。

#### ports: ホストとコンテナのポート対応

```yaml
web:
  ports:
    - "8080:80"      # ホストの 8080 → コンテナの 80
db:
  ports:
    - "33060:3306"   # ホストの 33060 → コンテナの 3306
```

書式は `ホスト側:コンテナ側` です。

- ブラウザからは `localhost:8080` でアクセスします。
- DB をホスト側で 33060 にしているのは、ホストに MySQL が入っていて 3306 を使っていても衝突しないようにするためです。
- `app` には ports が無いので、ホストから直接は届きません。Nginx からだけ使われるためです。

**注意:** コンテナ同士の通信では ports の設定は関係ありません。
Laravel から DB へは `db:3306`（コンテナ側のポート）で接続します。`33060` はホストから接続するときだけ使います。

#### サービス名による名前解決

Compose は自動で専用ネットワークを作り、**サービス名をホスト名として** 名前解決できるようにします。
そのため設定ファイルに IP アドレスを書く必要がありません。

| どこで | 書いている値 | 意味 |
|---|---|---|
| src/.env | `DB_HOST=db` | Laravel から db サービスへ |
| docker/nginx/default.conf | `fastcgi_pass app:9000;` | Nginx から app サービスへ |

#### depends_on と healthcheck

```yaml
app:
  depends_on:
    db:
      condition: service_healthy
db:
  healthcheck:
    test: ["CMD", "mysqladmin", "ping", "-h", "localhost", "-uroot", "-proot"]
    interval: 5s
    retries: 20
```

`depends_on` だけだと「db コンテナが **起動した**」時点で app が起動します。
しかし MySQL はプロセス起動から接続を受け付けるまで数秒〜数十秒かかります（初回は特に長い）。
`condition: service_healthy` を付けると、healthcheck の `mysqladmin ping` が成功するまで app の起動を待ちます。

#### environment

```yaml
db:
  environment:
    MYSQL_DATABASE: laravel
    MYSQL_USER: laravel
    MYSQL_PASSWORD: secret
    MYSQL_ROOT_PASSWORD: root
```

公式 MySQL イメージは、**初回起動時に** これらの値を使って DB とユーザーを作成します。
2回目以降はボリュームに既にデータがあるので無視されます。パスワードを後から変えても反映されないのはこのためです。

#### .env (ルート) と build args

```yaml
app:
  build:
    args:
      UID: ${UID:-1000}
      GID: ${GID:-1000}
```

`${UID:-1000}` は「ルートの `.env` に UID があればそれを、無ければ 1000 を使う」という意味です。
Compose は `docker-compose.yml` と同じディレクトリの `.env` を自動で読み込みます。

**ルートの `.env` と `src/.env` は別物です。**

| ファイル | 読むのは誰か | 中身 |
|---|---|---|
| `./.env` | Docker Compose | UID / GID |
| `./src/.env` | Laravel | DB 接続先、APP_KEY など |

### 2-2. docker/php/Dockerfile

```dockerfile
FROM php:8.4-fpm
```

公式 PHP イメージの FPM 版をベースにします。

```dockerfile
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev default-mysql-client \
    && docker-php-ext-install pdo_mysql zip bcmath \
    && apt-get clean && rm -rf /var/lib/apt/lists/*
```

- `docker-php-ext-install` は公式イメージに付属する、PHP 拡張を入れるためのコマンドです。
- `pdo_mysql` が無いと Laravel から MySQL に接続できません。
- 1つの `RUN` に `&&` でまとめ、最後にキャッシュを消しているのはイメージを小さくするためです。`RUN` ごとにレイヤーができるので、別の `RUN` で消しても容量は減りません。

```dockerfile
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
```

マルチステージビルドの書き方で、公式 Composer イメージから実行ファイルだけをコピーしています。
これでホストの Mac に PHP や Composer を入れずに済みます。

```dockerfile
RUN groupmod -o -g ${GID} www-data && usermod -o -u ${UID} -g ${GID} www-data \
    && chown www-data:www-data /var/www
USER www-data
```

コンテナ内のプロセスは何も指定しないと root で動きます。すると artisan が作ったファイル
（マイグレーションやログなど）がホスト側で root 所有になり、エディタで編集できなくなることがあります。
コンテナ内の `www-data` の UID/GID をホストのユーザーと合わせることでこれを防いでいます。
`/var/www` は www-data のホームディレクトリで、`artisan tinker` が設定を保存するために書き込み可能にしています。

### 2-3. docker/php/php.ini

```ini
date.timezone = Asia/Tokyo
```

PHP・MySQL・Laravel のタイムゾーンを Asia/Tokyo に揃えています。
Phase 7 の「有効期限付き編集ロック」では `現在時刻 < locked_until` で判定するため、
タイムゾーンがずれていると「ロックが9時間早く切れる」といった誤判定が起こります。

| 設定場所 | 設定 |
|---|---|
| docker/php/php.ini | `date.timezone = Asia/Tokyo` |
| docker/mysql/my.cnf | `default-time-zone = '+09:00'` |
| src/config/app.php | `'timezone' => env('APP_TIMEZONE', 'Asia/Tokyo')` |

### 2-4. docker/nginx/default.conf

```nginx
root /var/www/html/public;
```

Laravel は `public/` だけを公開します。`src/.env` などを外から読まれないようにするためです。

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

リクエストされたパスに実ファイル（CSS や画像）があればそれを返し、無ければ `index.php` に渡します。
`/reservations/1/edit` のような URL はファイルとして存在しないので、Laravel のルーターが処理します。

```nginx
location ~ \.php$ {
    fastcgi_pass app:9000;
    fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    include fastcgi_params;
}
```

Nginx 自身は PHP を実行できません。FastCGI というプロトコルで app コンテナの PHP-FPM に処理を依頼します。
web と app の両方に `./src` をマウントしているのは、両者が同じパス `/var/www/html` でファイルを参照する必要があるためです。

### 2-5. docker/mysql/my.cnf

排他制御の検証に直結する設定です。

| 設定 | 値 | 理由 |
|---|---|---|
| `transaction-isolation` | REPEATABLE-READ | InnoDB のデフォルトだが、排他制御の挙動は分離レベルに依存するので前提として明示 |
| `innodb_lock_wait_timeout` | 10 | 行ロックの待ち時間の上限。デフォルト 50 秒では悲観的ロックの検証で待ちすぎるため短縮 |
| `general_log` | 1 | 発行されたすべての SQL を記録する。本番では使わない |
| `log_timestamps` | SYSTEM | ログの時刻を UTC ではなく JST で出す |
| `character-set-server` | utf8mb4 | 日本語の顧客名を扱うため |

### 2-6. docker/mysql/init/01_create_testing_db.sql

公式 MySQL イメージは、`/docker-entrypoint-initdb.d/` にある `.sql` を **データディレクトリが空のとき（初回起動時）だけ** 実行します。
ここで Phase 8 用の `laravel_testing` データベースを作成しています。

後からこのファイルを変更しても、既存のボリュームがある限り再実行されません。
反映したい場合はボリュームごと作り直します（DB のデータは消えます）。

```sh
docker compose down -v
docker compose up -d
docker compose exec app php artisan migrate
```

---

## 3. よく使うコマンド

### 起動・停止

```sh
docker compose up -d            # バックグラウンドで起動
docker compose up -d --build    # Dockerfile を変更したときはビルドし直して起動
docker compose ps               # 状態確認
docker compose stop             # 停止（コンテナは残る）
docker compose down             # 停止してコンテナ削除（DB データは残る）
docker compose down -v          # ボリュームも削除（DB データも消える）
```

### コンテナ内でコマンドを実行

```sh
docker compose exec app php artisan migrate
docker compose exec app php artisan tinker
docker compose exec app composer require <package>
docker compose exec app bash                         # app コンテナに入る
docker compose exec db mysql -ularavel -psecret laravel   # MySQL クライアント
```

`exec` は **起動中のコンテナ** でコマンドを実行します。
`docker compose run --rm app ...` は新しいコンテナを一時的に作って実行し、終わったら消します。

### ログ

```sh
docker compose logs -f web      # Nginx のアクセスログ
docker compose logs -f app      # PHP-FPM のログ
tail -f src/storage/logs/laravel.log   # Laravel のログ（ホストから直接読める）
```

---

## 4. 排他制御の検証で使う観察方法

### 発行された SQL を見る (General Query Log)

```sh
docker compose exec db tail -f /var/lib/mysql/general.log
```

出力例:

```
2026-10-02T01:18:15.195627+09:00	    9 Prepare	select @@transaction_isolation as v
2026-10-02T01:18:15.195713+09:00	    9 Execute	select @@transaction_isolation as v
```

| 列 | 意味 |
|---|---|
| 1列目 | 時刻 |
| 2列目 | **接続 ID**。PHP-FPM は **リクエストごとに新しい DB 接続** を作るので、1リクエスト分の SQL が同じ番号でまとまる。同じ利用者でも次のリクエストでは番号が変わる |
| 3列目 | 種類。Laravel はプリペアドステートメントを使うので `Prepare` と `Execute` が出る。`Execute` 行にはバインド値が埋め込まれた SQL が出る |

### 現在のロックを見る (Phase 6 以降で使用)

```sh
docker compose exec db mysql -uroot -proot -e "
  SELECT ENGINE_TRANSACTION_ID, OBJECT_NAME, INDEX_NAME, LOCK_TYPE, LOCK_MODE, LOCK_STATUS, LOCK_DATA
  FROM performance_schema.data_locks;"
```

`LOCK_STATUS` が `GRANTED` ならロック取得済み、`WAITING` ならロック待ちです。

### GUI クライアントから接続する

TablePlus や Sequel Ace などから以下で接続できます。

| 項目 | 値 |
|---|---|
| Host | 127.0.0.1 |
| Port | 33060 |
| User / Password | laravel / secret（root / root でも可） |
| Database | laravel |

---

## 5. トラブルシューティング

### Mounts denied: The path ... is not shared from the host

Phase 1 で実際に発生したエラーです。

```
Error response from daemon: Mounts denied:
The path /Applications/github/laravel-exclusive-control/src is not shared from the host and is not known to Docker.
```

Mac の Docker Desktop は Linux の仮想マシン上でコンテナを動かしています。
バインドマウントできるのは、Docker Desktop が仮想マシンに共有しているホストのディレクトリだけです。
デフォルトでは `/Users`、`/Volumes`、`/private`、`/tmp`、`/var/folders` だけが共有されていて、`/Applications` は含まれません。

**解決方法:** Docker Desktop の Settings → Resources → File sharing にプロジェクトのパスを追加し、Apply & restart します。
プロジェクトを `/Users/<ユーザー名>/` 配下に置く方法でも解決します。

### ポートが使われている (port is already allocated)

ホストの 8080 や 33060 を別のプロセスが使っています。`docker-compose.yml` の ports のホスト側の番号を変えてください。

### SQLSTATE[HY000] [2002] Connection refused

- `src/.env` の `DB_HOST` が `127.0.0.1` になっていないか確認します。コンテナ内の `127.0.0.1` は app コンテナ自身を指すので、`db` にする必要があります。
- DB の起動直後は接続できないことがあります。`docker compose ps` で db が `healthy` になっているか確認します。

### my.cnf の変更が反映されない

MySQL は起動時に設定を読むので、再起動が必要です。

```sh
docker compose restart db
docker compose exec db mysql -uroot -proot -e "SELECT @@innodb_lock_wait_timeout;"
```
