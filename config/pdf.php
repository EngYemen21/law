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
    // مسارا Node وكروم الصريحان (اختياريّان — وإلّا يُكتشفان من المواضع المعتادة). كانا يُقرآن بـ`env()` داخل
    // `PdfRenderer` فيعودان فارغين بعد `config:cache` الذي يشغّله النشر (فصل البيئات 2026-09-29)
    'node_binary' => env('NODE_BINARY') ?: env('NODE_PATH'),
    'chrome_path' => env('CHROME_PATH') ?: env('PUPPETEER_EXECUTABLE_PATH'),
];
