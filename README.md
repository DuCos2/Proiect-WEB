# Proiect-WEB

Acesta este un proiect pentru disciplina Web.
Link prezentare: https://drive.google.com/drive/folders/1GrsLW_OdaFvO8OzD-O9mB-pcvvdaQ7dd?usp=sharing

## Rulare locala

Pentru autentificare este nevoie de PHP si MySQL prin XAMPP:

1. Porneste Apache si MySQL din XAMPP.
2. Importa `backend/Database/Web_LoG.sql` in baza de date `log_iasi`.
3. Deschide aplicatia prin symlink-ul din XAMPP:

```text
http://localhost/Proiect-WEB/
```




Pentru trimitere reala prin Gmail SMTP:

1. Copiaza
```php
<?php

return [
    'host' => 'smtp.gmail.com',
    'port' => 587,
    'username' => '',
    'password' => '',
    'encryption' => 'tls',
    'from_email' => '',
    'from_name' => 'Local Greetings',
];
``` 

in `backend/config/mail.local.php`.
3. Completeaza adresa Gmail(la username si la from_email) si un Google App Password(la password).
