# نظام إدارة الزكاة — Zakat SaaS (Single Org)

لوحة إدارة كاملة للجان الزكاة والجمعيات الخيرية، مبنية بـ **PHP + MySQL + Bootstrap 5 RTL**.

---

## المميزات

- **لوحة تحكم** — إحصائيات، بطاقات إجمالية، آخر التوزيعات
- **إدارة المستفيدين** — إضافة / تعديل / حذف / بحث + ترقيم تلقائي
- **التوزيعات** — إنشاء توزيعة، اختيار مستفيدين، تسجيل المبالغ والتفاصيل
- **سجل المستفيد** — عرض كل الاستلامات مع تصفية بالتاريخ
- **استيراد البيانات** — CSV أو لصق من Excel مع معاينة وكشف تلقائي للأعمدة (RTL)
- **طباعة الكشوف** — كشف طباعي نظيف بدون عناصر واجهة
- **الإعدادات** — اسم المنظمة، هاتف، بريد + تغيير كلمة المرور
- **أمان** — جلسات، CSRF، تشفير كلمة المرور (bcrypt)، PRG على جميع POST

---

## متطلبات التشغيل

- PHP 8.1 أو أحدث
- MySQL 5.7+ أو MariaDB 10.4+
- خادم ويب: Apache (مع mod_rewrite) أو Nginx

---

## خطوات التثبيت

### 1. نسخ الملفات

```bash
git clone https://github.com/moded13/zakat-saas.git
```

### 2. إنشاء قاعدة البيانات

```bash
mysql -u root -p < database/schema.sql
```

أو نفّذ محتوى `database/schema.sql` يدوياً من phpMyAdmin.

### 3. إنشاء هاش كلمة المرور (اختياري)

لتغيير الهاش الافتراضي، شغّل:

```bash
php -r "echo password_hash('كلمة_المرور_الجديدة', PASSWORD_BCRYPT);"
```

ثم حدّث جدول `admins` بالهاش الجديد.

> بيانات الدخول الافتراضية:
> - المستخدم: `admin`
> - كلمة المرور: `123@123`

### 4. ضبط الإعدادات

افتح `admin/bootstrap.php` وعدّل:

```php
define('DB_HOST',    'localhost');
define('DB_NAME',    'zakat_db');
define('DB_USER',    'root');
define('DB_PASS',    '');
define('ADMIN_PATH', '/zakat/admin');  // المسار من جذر الموقع
```

### 5. رفع الملفات

ضع مجلد المشروع بالكامل داخل مجلد الموقع (مثل `public_html` أو `htdocs`):

```
public_html/
└── zakat/
    └── admin/
        ├── bootstrap.php
        ├── layout.php
        ├── login.php
        ├── dashboard.php
        └── ...
```

### 6. الدخول للوحة التحكم

افتح المتصفح على:

```
http://yourdomain.com/zakat/admin/login.php
```

---

## هيكل الملفات

```
zakat-saas/
├── database/
│   └── schema.sql          # جداول + بيانات أولية
├── admin/
│   ├── bootstrap.php       # إعدادات، جلسة، PDO، auth، CSRF، flash
│   ├── layout.php          # sidebar + header + footer + renderPage()
│   ├── login.php           # تسجيل الدخول
│   ├── logout.php          # تسجيل الخروج
│   ├── dashboard.php       # لوحة التحكم
│   ├── beneficiaries.php   # إدارة المستفيدين (إضافة/تعديل/حذف/بحث)
│   ├── distributions.php   # إنشاء وعرض التوزيعات
│   ├── beneficiary_history.php  # سجل مستفيد
│   ├── import.php          # استيراد CSV / Excel
│   ├── print_distribution.php  # كشف طباعة
│   └── settings.php        # إعدادات المنظمة
└── README.md
```

---

## جداول قاعدة البيانات

| الجدول | الوصف |
|--------|-------|
| `admins` | مستخدمو النظام (admin واحد) |
| `beneficiary_types` | أنواع المستفيدين (5 أنواع ثابتة) |
| `beneficiaries` | جميع المستفيدين (جدول موحّد) |
| `distributions` | رؤوس التوزيعات |
| `distribution_items` | بنود كل توزيعة |
| `attachments` | مرفقات (stub للاستخدام المستقبلي) |
| `settings` | إعدادات المنظمة |

---

## الأمان

- كلمات المرور مشفّرة بـ bcrypt
- CSRF token على جميع نماذج POST
- PRG (Post-Redirect-Get) لمنع تكرار الإرسال
- تنقية جميع المدخلات (htmlspecialchars)
- Prepared Statements لمنع SQL Injection
- `session.use_strict_mode = 1`
- تجديد Session ID عند تسجيل الدخول

---

## المساهمة

هذا المشروع في طور التطوير. الترحيب بأي اقتراح أو مساهمة.
