<?php
/* Copia este archivo como config.php y llena tus datos de MySQL (hPanel → Bases de datos). */
return [
  'db_host' => 'localhost',
  'db_name' => 'u123456789_tienda',   // nombre de la base de datos
  'db_user' => 'u123456789_tienda',   // usuario de la base de datos
  'db_pass' => 'TU_CONTRASEÑA_MYSQL',
  'timezone' => 'America/Bogota',
  'cookie_secure' => true,            // true si tu sitio usa https (recomendado)

  // Correos automáticos (opcional): cuenta de https://resend.com con tu dominio verificado
  'resend_key' => '',
  'mail_from' => '',                  // Ej: 'Golden Touch <ventas@tudominio.com>'

  // WhatsApp automático (opcional): WhatsApp Cloud API de Meta
  'wa_token' => '',
  'wa_phone_id' => '',

  // Clave para el cron de carritos abandonados (cualquier texto largo y secreto)
  'cron_key' => 'CAMBIA-ESTA-CLAVE',
];
