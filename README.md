# Proxmox Rental Panel

友人向けに、テンプレートVMからVPSを作成・起動・停止・削除できる軽量なPHPパネルです。依存パッケージやNode.jsは不要で、ApacheとPHP、MariaDBだけで動作します。

## できること

- 管理者: 利用者と貸出プランを作成
- 利用者: 自分のVMを作成、起動、通常停止、強制停止、削除
- ProxmoxのテンプレートVMからフルクローンを作成
- 利用者とVMの対応をDBで強制。利用者は他人のVMを操作不可
- 作成時にProxmoxノードの実測使用率と、既存VMに予約済みのCPU／メモリを確認。空き不足時は作成を拒否

## 必要環境

- Apache + PHP 8.1以上（`curl`, `pdo_mysql` 拡張を有効化）。VM作成を待てるよう `max_execution_time` は120秒以上を推奨
- MariaDB 10.4+ または MySQL 8+
- Proxmox VE 8+ と、クローン元となる**テンプレートVM**

## 設置手順

1. Apache公開ディレクトリでリポジトリをcloneします。

   ```bash
   cd /var/www/html
   git clone https://github.com/あなたのGitHubユーザー名/proxmox-rental-panel.git
   ```

   Gitを使わない場合は、このディレクトリをApache公開ディレクトリへコピーしても構いません。
2. MariaDBに空のDBと、そのDBだけを操作できるユーザーを作ります。

   ```sql
   CREATE DATABASE rental_panel CHARACTER SET utf8mb4;
   CREATE USER 'rental'@'localhost' IDENTIFIED BY '強いパスワード';
   GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES
     ON rental_panel.* TO 'rental'@'localhost';
   ```

3. `config` ディレクトリを、セットアップ時だけApache実行ユーザーが書き込み可能にします。例: `chmod 770 config`。
4. ブラウザで設置URLを開き、初期セットアップを完了します。ここでProxmoxのURL、API Token ID、Secretを一度入力するだけです。完了後は `config/config.php` をWebサーバーだけが読める権限（例: `640`）に戻します。
5. 管理画面で「プラン」を作成し、利用者を追加します。

### 更新方法

サーバー上で次を実行します。`config/config.php` は `.gitignore` により更新対象にならず、初回セットアップの接続情報は維持されます。

```bash
cd /var/www/html/proxmox-rental-panel
git pull --ff-only
```

## Proxmox側の準備

### 1. テンプレートを作る

UbuntuやDebianをVMにインストールして、Cloud-init、SSH鍵、初期ユーザーなどを準備した後、Proxmox GUIの **More → Convert to template** でテンプレート化します。作成されたVMIDをプランへ入力します。

このパネルはテンプレートを**フルクローン**します。保存先ストレージには、テンプレートのディスクをフルクローン可能な十分な空き容量が必要です。プランのディスク容量がテンプレートの起動ディスクより大きい場合は、クローン後に拡張します（縮小はしません）。ゲストOS内のパーティション／ファイルシステムの拡張はCloud-initなどで別途行ってください。

### 2. APIトークンを作る

Proxmox GUIで専用ユーザー（例: `panel@pve`）とAPIトークン（例: `rental`）を作成します。トークンの **Privilege Separation はオフ** にします。

最低限、対象ノード・対象ストレージ・テンプレートVMを含むプールだけに、クローン、設定変更、電源操作、監査閲覧の権限を与えてください。管理者トークンを入力してはいけません。最初は隔離した検証用ユーザーと1台のテストVMで権限を調整してください。

セットアップ画面には、`panel@pve!rental` のようなToken IDと、発行時に一度だけ表示されるSecretを入力します。

## 運用上の注意

- 容量判定は、CPUを物理コアの80%、メモリとディスクを各85%までに制限します。さらにProxmoxが報告する実測CPU／メモリ使用率が92%、ストレージ使用率が92%を超える間は作成を拒否します。値は `index.php` の `assert_capacity` で変更できます。

- Proxmoxの8006番ポートやこのパネルの管理者アカウントをインターネットへ無防備に公開しないでください。HTTPS、強いパスワード、管理者のIP制限またはVPNを使ってください。
- VM作成中はPHPリクエストが最大60秒待ちます。大きなディスクや遅いストレージではタイムアウトする場合がありますが、Proxmox側のタスクは継続していることがあります。
- この初版はSSH鍵の個別登録、コンソール、Discord Bot、請求機能は含みません。まず利用者のVM操作を安全に分離するための最小構成です。
- 削除は復元できません。Proxmox Backup Server等で別途バックアップを設定してください。
