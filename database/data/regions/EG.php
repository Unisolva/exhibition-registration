<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

/*
 * Egypt's 27 governorates. Format in docs/INSTALL.md ("Adding a country").
 *
 * code      the app's own code, stored on visitors (ISO 3166-2:EG without "EG-")
 * erp_code  exactly Odoo's res.country.state code for that state; for Egypt it
 *           equals the app code. Must be confirmed against the ERP before use.
 * name_ar   draft; to be checked by the owner before release (work order §16.11).
 */
return [
    'country_code' => 'EG',
    'field_label_en' => 'Governorate',
    'field_label_ar' => 'المحافظة',
    'regions' => [
        ['code' => 'ALX', 'erp_code' => 'ALX', 'name_en' => 'Alexandria', 'name_ar' => 'الإسكندرية'],
        ['code' => 'ASN', 'erp_code' => 'ASN', 'name_en' => 'Aswan', 'name_ar' => 'أسوان'],
        ['code' => 'AST', 'erp_code' => 'AST', 'name_en' => 'Asyut', 'name_ar' => 'أسيوط'],
        ['code' => 'BH', 'erp_code' => 'BH', 'name_en' => 'Beheira', 'name_ar' => 'البحيرة'],
        ['code' => 'BNS', 'erp_code' => 'BNS', 'name_en' => 'Beni Suef', 'name_ar' => 'بني سويف'],
        ['code' => 'C', 'erp_code' => 'C', 'name_en' => 'Cairo', 'name_ar' => 'القاهرة'],
        ['code' => 'DK', 'erp_code' => 'DK', 'name_en' => 'Dakahlia', 'name_ar' => 'الدقهلية'],
        ['code' => 'DT', 'erp_code' => 'DT', 'name_en' => 'Damietta', 'name_ar' => 'دمياط'],
        ['code' => 'FYM', 'erp_code' => 'FYM', 'name_en' => 'Faiyum', 'name_ar' => 'الفيوم'],
        ['code' => 'GH', 'erp_code' => 'GH', 'name_en' => 'Gharbia', 'name_ar' => 'الغربية'],
        ['code' => 'GZ', 'erp_code' => 'GZ', 'name_en' => 'Giza', 'name_ar' => 'الجيزة'],
        ['code' => 'IS', 'erp_code' => 'IS', 'name_en' => 'Ismailia', 'name_ar' => 'الإسماعيلية'],
        ['code' => 'KFS', 'erp_code' => 'KFS', 'name_en' => 'Kafr El Sheikh', 'name_ar' => 'كفر الشيخ'],
        ['code' => 'LX', 'erp_code' => 'LX', 'name_en' => 'Luxor', 'name_ar' => 'الأقصر'],
        ['code' => 'MT', 'erp_code' => 'MT', 'name_en' => 'Matrouh', 'name_ar' => 'مطروح'],
        ['code' => 'MN', 'erp_code' => 'MN', 'name_en' => 'Minya', 'name_ar' => 'المنيا'],
        ['code' => 'MNF', 'erp_code' => 'MNF', 'name_en' => 'Monufia', 'name_ar' => 'المنوفية'],
        ['code' => 'WAD', 'erp_code' => 'WAD', 'name_en' => 'New Valley', 'name_ar' => 'الوادي الجديد'],
        ['code' => 'SIN', 'erp_code' => 'SIN', 'name_en' => 'North Sinai', 'name_ar' => 'شمال سيناء'],
        ['code' => 'PTS', 'erp_code' => 'PTS', 'name_en' => 'Port Said', 'name_ar' => 'بورسعيد'],
        ['code' => 'KB', 'erp_code' => 'KB', 'name_en' => 'Qalyubia', 'name_ar' => 'القليوبية'],
        ['code' => 'KN', 'erp_code' => 'KN', 'name_en' => 'Qena', 'name_ar' => 'قنا'],
        ['code' => 'BA', 'erp_code' => 'BA', 'name_en' => 'Red Sea', 'name_ar' => 'البحر الأحمر'],
        ['code' => 'SHR', 'erp_code' => 'SHR', 'name_en' => 'Sharqia', 'name_ar' => 'الشرقية'],
        ['code' => 'SHG', 'erp_code' => 'SHG', 'name_en' => 'Sohag', 'name_ar' => 'سوهاج'],
        ['code' => 'JS', 'erp_code' => 'JS', 'name_en' => 'South Sinai', 'name_ar' => 'جنوب سيناء'],
        ['code' => 'SUZ', 'erp_code' => 'SUZ', 'name_en' => 'Suez', 'name_ar' => 'السويس'],
    ],
];
