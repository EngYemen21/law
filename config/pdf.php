<?php

/*
 * إعدادات مصيّر PDF (PdfRenderer) — تُضبط من .env على السيرفر دون لمس الكود:
 *   PDF_ENGINE=auto|browsershot|native
 *     auto (الافتراضي): كروم إن توفّر (فحص مسبق يمنع التعليق)، وإلا الاحتياطي الأصلي.
 *     native: تجاوز كروم كليّاً — حلّ فوري إن استمرّ التعليق على الاستضافة.
 *     browsershot: فرض كروم دائماً (بيئات مضمونة التجهيز).
 *   PDF_TIMEOUT: مهلة كروم بالثواني. الافتراض 20 — أقل من max_execution_time الشائع (30s
 *     على cPanel) عمداً: فيرمي Browsershot استثناءً قابلاً للالتقاط قبل سقوط PHP بخطأ فادح
 *     كان يُعيد صفحة HTML بدل الـPDF.
 */

return [
    'engine' => env('PDF_ENGINE', 'auto'),
    'timeout' => (int) env('PDF_TIMEOUT', 20),
];
