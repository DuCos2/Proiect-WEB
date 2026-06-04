# Proiect-WEB

Acesta este un proiect pentru disciplina Web.

## Structura proiectului

- `index.html` - pagina principala cu evenimente noi
- `search.html` - pagina de cautare cu harta si advanced search
- `profile.html` - pagina de profil
- `login.html` / `register.html` - pagini statice pentru autentificare
- `assets/css/` - fisiere CSS
- `assets/js/` - fisiere JavaScript
- `assets/images/` - imagini
- `assets/media/` - fisiere audio/video
- `docs/` - documente si PDF-uri
- `examples/` - exemple HTML pastrate separat de aplicatia principala

## Rulare locala

Pentru autentificare este nevoie de PHP si MySQL prin XAMPP:

1. Porneste Apache si MySQL din XAMPP.
2. Importa `backend/Database/Web_LoG.sql` in baza de date `log_iasi`.
3. Deschide aplicatia prin symlink-ul din XAMPP:

```text
http://localhost/Proiect-WEB/
```

Paginile HTML nu trebuie deschise direct din filesystem pentru login/register, deoarece formularele folosesc servicii PHP prin `fetch`.

## Email local si SMTP

Emailurile pentru verificare cont si resetare parola sunt salvate local in:

```text
backend/storage/mail.log
```

Pentru trimitere reala prin Gmail SMTP:

1. Copiaza `backend/config/mail.example.php` in `backend/config/mail.local.php`.
2. Completeaza adresa Gmail si un Google App Password.
3. Nu urca `mail.local.php` pe GitHub; este ignorat prin `.gitignore`.
