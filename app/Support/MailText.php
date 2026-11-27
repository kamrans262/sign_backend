<?php

namespace App\Support;

/** Fixed mail chrome, not recipient names, verification codes, or business content. */
final class MailText
{
    private const COPY = [
        'Sign2Digital — Company invitation' => ['fr'=>'Sign2Digital — Invitation à rejoindre l’entreprise','de'=>'Sign2Digital — Firmeneinladung','it'=>'Sign2Digital — Invito aziendale'],
        'Sign2Digital — verify your new email' => ['fr'=>'Sign2Digital — Vérifiez votre nouvelle adresse e-mail','de'=>'Sign2Digital — Neue E-Mail-Adresse bestätigen','it'=>'Sign2Digital — Verifica la nuova email'],
        'Equipment return reminder — ' => ['fr'=>'Rappel de retour de matériel — ','de'=>'Erinnerung an Geräterückgabe — ','it'=>'Promemoria restituzione attrezzatura — '],
        'Verify your email' => ['fr'=>'Vérifiez votre e-mail','de'=>'E-Mail-Adresse bestätigen','it'=>'Verifica la tua email'],
        'Your sign-in code' => ['fr'=>'Votre code de connexion','de'=>'Ihr Anmeldecode','it'=>'Il tuo codice di accesso'],
        'Reset your password' => ['fr'=>'Réinitialiser votre mot de passe','de'=>'Passwort zurücksetzen','it'=>'Reimposta la password'],
        'Your verification code' => ['fr'=>'Votre code de vérification','de'=>'Ihr Bestätigungscode','it'=>'Il tuo codice di verifica'],
        'Enter this code in the Sign2Digital app:' => ['fr'=>'Saisissez ce code dans l’application Sign2Digital :','de'=>'Geben Sie diesen Code in der Sign2Digital-App ein:','it'=>'Inserisci questo codice nell’app Sign2Digital:'],
        'This code expires in' => ['fr'=>'Ce code expire dans','de'=>'Dieser Code läuft ab in','it'=>'Questo codice scade tra'],
        '5 minutes' => ['fr'=>'5 minutes','de'=>'5 Minuten','it'=>'5 minuti'],
        'Do not share it with anyone.' => ['fr'=>'Ne le communiquez à personne.','de'=>'Geben Sie ihn nicht weiter.','it'=>'Non condividerlo con nessuno.'],
        'If you did not request this code, you can ignore this email.' => ['fr'=>'Si vous n’avez pas demandé ce code, ignorez ce message.','de'=>'Falls Sie diesen Code nicht angefordert haben, ignorieren Sie diese E-Mail.','it'=>'Se non hai richiesto questo codice, ignora questa email.'],
        'has invited you to join' => ['fr'=>'vous a invité à rejoindre','de'=>'hat Sie eingeladen, beizutreten','it'=>'ti ha invitato a unirti a'],
        'Sign in or create your account in Sign2Digital using this email address. Open Company Invitations and enter the invitation code:' => ['fr'=>'Connectez-vous ou créez un compte Sign2Digital avec cette adresse e-mail. Ouvrez Invitations d’entreprise et saisissez le code :','de'=>'Melden Sie sich mit dieser E-Mail-Adresse bei Sign2Digital an oder erstellen Sie ein Konto. Öffnen Sie Firmeneinladungen und geben Sie den Einladungscode ein:','it'=>'Accedi o crea un account Sign2Digital con questo indirizzo email. Apri Inviti aziendali e inserisci il codice:'],
        'This invitation expires in seven days and can only be accepted by the email address receiving it. Do not share the invitation code.' => ['fr'=>'Cette invitation expire dans sept jours et ne peut être acceptée que depuis cette adresse e-mail. Ne partagez pas le code.','de'=>'Diese Einladung läuft in sieben Tagen ab und kann nur über die Empfängeradresse angenommen werden. Geben Sie den Code nicht weiter.','it'=>'L’invito scade tra sette giorni e può essere accettato solo dall’indirizzo email destinatario. Non condividere il codice.'],
        'If you did not expect this invitation, ignore this message.' => ['fr'=>'Si vous n’attendiez pas cette invitation, ignorez ce message.','de'=>'Wenn Sie diese Einladung nicht erwartet haben, ignorieren Sie diese Nachricht.','it'=>'Se non ti aspettavi questo invito, ignora il messaggio.'],
        'Hello' => ['fr'=>'Bonjour','de'=>'Hallo','it'=>'Ciao'],
        'This is a reminder from' => ['fr'=>'Voici un rappel de','de'=>'Dies ist eine Erinnerung von','it'=>'Questo è un promemoria da'],
        'about the equipment currently on loan:' => ['fr'=>'concernant le matériel actuellement emprunté :','de'=>'bezüglich des derzeit ausgeliehenen Geräts:','it'=>'relativo all’attrezzatura attualmente in prestito:'],
        'Expected return date:' => ['fr'=>'Date de retour prévue :','de'=>'Voraussichtliches Rückgabedatum:','it'=>'Data di restituzione prevista:'],
        'If you have already returned it or need more time, please contact the lending company directly.' => ['fr'=>'Si vous l’avez déjà restitué ou avez besoin de plus de temps, contactez directement l’entreprise prêteuse.','de'=>'Wenn Sie das Gerät bereits zurückgegeben haben oder mehr Zeit benötigen, wenden Sie sich direkt an die verleihende Firma.','it'=>'Se l’hai già restituita o ti serve più tempo, contatta direttamente l’azienda che l’ha prestata.'],
        'Sign2Digital equipment lending records' => ['fr'=>'Registre de prêt de matériel Sign2Digital','de'=>'Sign2Digital-Geräteausleihdaten','it'=>'Registrazioni prestiti attrezzature Sign2Digital'],
        'Your Sign2Digital email change code is' => ['fr'=>'Votre code de changement d’e-mail Sign2Digital est','de'=>'Ihr Sign2Digital-Code zur E-Mail-Änderung lautet','it'=>'Il codice Sign2Digital per modificare l’email è'],
        'It expires in 10 minutes. If you did not request this change, ignore this email.' => ['fr'=>'Il expire dans 10 minutes. Si vous n’avez pas demandé ce changement, ignorez cet e-mail.','de'=>'Er läuft in 10 Minuten ab. Wenn Sie diese Änderung nicht angefordert haben, ignorieren Sie die E-Mail.','it'=>'Scade tra 10 minuti. Se non hai richiesto questa modifica, ignora l’email.'],
    ];

    public static function tr(string $label): string
    {
        return self::COPY[$label][AppLocale::current()] ?? $label;
    }
}
