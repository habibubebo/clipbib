# ClipBib

Catatan simpel tanpa login, tersimpan sebagai file `.md`. Mobile-first, tampilan seperti app.

## Requirements

- PHP 7.4+
- Web server (Apache/Nginx) atau **Laragon** (untuk local dev)
- Folder `notes/` writable oleh web server

## Cara Install

### Local (Laragon)

1. Clone repo:

```bash
cd C:\laragon\www
git clone https://github.com/habibubebo/clipbib.git
```

2. Buka browser, akses:

```
http://localhost/clipbib
```

Folder `notes/` otomatis dibuat saat pertama kali diakses.

### Deploy ke Hosting (cPanel/VPS)

1. Login ke hosting panel / SSH ke VPS

2. Clone atau upload project:

```bash
cd /home/username/public_html
git clone https://github.com/habibubebo/clipbib.git clipbib
```

3. Pastikan folder `notes/` bisa ditulis:

```bash
chmod 755 clipbib/notes
```

4. Akses melalui domain:

```
https://domain.com/clipbib
```

### Deploy ke VPS (Nginx)

1. Clone repo:

```bash
cd /var/www
git clone https://github.com/habibubebo/clipbib.git clipbib
```

2. Buat config Nginx (`/etc/nginx/sites-available/clipbib`):

```nginx
server {
    listen 80;
    server_name domain.com;
    root /var/www/clipbib;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/var/run/php/php-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    location ~ /\.ht {
        deny all;
    }
}
```

3. Aktifkan config & restart:

```bash
ln -s /etc/nginx/sites-available/clipbib /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx
```

4. Pastikan folder `notes/` writable:

```bash
chmod 755 /var/www/clipbib/notes
```

## Penggunaan

### Membuat Catatan Baru

- Ketuk tombol **+** (kanan bawah)
- Isi judul, tags (opsional, pisahkan koma), dan isi catatan
- Tekan **Simpan**

### Membaca Catatan

- Ketuk card catatan di halaman utama
- Format markdown otomatis di-render (heading, bold, italic, code, link, list)

### Edit Catatan

- Buka catatan, ketuk tombol **Edit** (kanan bawah)
- Ubah isi, lalu **Simpan**

### Hapus Catatan

- Buka catatan untuk diedit
- Ketuk tombol **Hapus** di pojok kanan atas editor

### Cari Catatan

- Ketik di kolom pencarian di bagian atas
- Hasil otomatis muncul (debounce 400ms)

## Format Markdown yang Didukung

```
# Heading 1
## Heading 2
### Heading 3
**bold text**
*italic text*
`inline code`
- list item
[link text](https://url.com)
```

## Struktur File

```
clipbib/
  index.php          <- seluruh aplikasi (satu file)
  notes/             <- catatan tersimpan di sini (.md)
    20260911_143022_My_Note.md
    20260911_150500_Todo_List.md
  README.md
```

## Lisensi

MIT
