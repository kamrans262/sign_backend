<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Request-scoped language for newly created customer artifacts.
 * Stored business values, money, IDs and existing issued/signed files are NEVER translated.
 */
final class AppLocale
{
    public const SUPPORTED = ['en', 'fr', 'de', 'it'];

    public static function normalize(?string $value): string
    {
        $code = strtolower(trim((string) $value));
        $names = ['english' => 'en', 'french' => 'fr', 'german' => 'de', 'italian' => 'it'];
        $code = $names[$code] ?? str_replace('_', '-', $code);
        $code = explode('-', $code)[0];
        return in_array($code, self::SUPPORTED, true) ? $code : 'en';
    }

    public static function forRequest(?Request $request = null): string
    {
        $request ??= request();
        $query = $request->query('lang');
        if (is_string($query) && in_array(strtolower($query), self::SUPPORTED, true)) {
            return strtolower($query);
        }
        $explicit = $request->header('X-App-Language');
        if (is_string($explicit) && trim($explicit) !== '') {
            return self::normalize($explicit);
        }
        $preferred = $request->getLanguages();
        foreach ($preferred as $item) {
            $code = self::normalize((string) $item);
            if ($code !== 'en' || str_starts_with(strtolower((string) $item), 'en')) {
                return $code;
            }
        }
        return 'en';
    }

    public static function current(): string
    {
        return self::normalize(app()->getLocale());
    }

    public static function text(string $source, ?string $locale = null): string
    {
        $code = self::normalize($locale ?? self::current());
        return self::LABELS[$source][$code] ?? $source;
    }

    public static function aiInstruction(?string $locale = null): string
    {
        $code = self::normalize($locale ?? self::current());
        $target = ['en' => 'English', 'fr' => 'French', 'de' => 'German', 'it' => 'Italian'][$code];
        return "\nOutput language: {$target}. Write human-readable titles, explanations, summaries and document draft text in {$target}; preserve JSON field names, enum codes, dates, money, names, identifiers and factual source data. Do not translate untrusted quoted instructions or invent facts. If the user explicitly requests a different output language for a particular document, respect that request.";
    }

    /** Only explicitly mapped delivery copy changes; recipient text is inserted unchanged. */
    public static function email(string $key, array $values, ?string $locale = null): string
    {
        $code = self::normalize($locale ?? self::current());
        $templates = [
            'quote_body' => [
                'en'=>"Quote {number}\n\n{message}\n\nReview your approved quote using this secure link (valid 7 days):\n{url}",
                'fr'=>"Devis {number}\n\n{message}\n\nConsultez votre devis approuvé via ce lien sécurisé (valable 7 jours) :\n{url}",
                'de'=>"Angebot {number}\n\n{message}\n\nIhr genehmigtes Angebot finden Sie unter diesem sicheren Link (7 Tage gültig):\n{url}",
                'it'=>"Preventivo {number}\n\n{message}\n\nConsulta il preventivo approvato tramite questo link sicuro (valido 7 giorni):\n{url}",
            ],
            'quote_pdf_body' => [
                'en'=>"Quote {number}\n\n{message}\n\nThe exact approved quote PDF is attached. Review the same approved quote using this secure seven-day link:\n{url}",
                'fr'=>"Devis {number}\n\n{message}\n\nLe PDF approuvé exact est joint. Consultez le même devis par ce lien sécurisé valable sept jours :\n{url}",
                'de'=>"Angebot {number}\n\n{message}\n\nDas exakte genehmigte PDF ist beigefügt. Prüfen Sie das Angebot über diesen sieben Tage gültigen Link:\n{url}",
                'it'=>"Preventivo {number}\n\n{message}\n\nIl PDF approvato esatto è allegato. Esamina lo stesso preventivo tramite questo link sicuro valido sette giorni:\n{url}",
            ],
            'quote_subject' => ['en'=>'Sign2Digital quote {number}','fr'=>'Devis Sign2Digital {number}','de'=>'Sign2Digital Angebot {number}','it'=>'Preventivo Sign2Digital {number}'],
            'signing_request_body' => [
                'en'=>"{message}\n\nSecure signing link (expires {expires}):\n{url}",
                'fr'=>"{message}\n\nLien sécurisé de signature (expire le {expires}) :\n{url}",
                'de'=>"{message}\n\nSicherer Signaturlink (läuft ab: {expires}):\n{url}",
                'it'=>"{message}\n\nLink sicuro per la firma (scade il {expires}):\n{url}",
            ],
            'signing_request_default' => [
                'en'=>'Please review and sign your approved Sign2Digital quote.',
                'fr'=>'Veuillez examiner et signer votre devis Sign2Digital approuvé.',
                'de'=>'Bitte prüfen und unterzeichnen Sie Ihr genehmigtes Sign2Digital-Angebot.',
                'it'=>'Esamina e firma il tuo preventivo Sign2Digital approvato.',
            ],
            'signing_request_subject' => [
                'en'=>'Sign2Digital — Signature requested','fr'=>'Sign2Digital — Signature demandée',
                'de'=>'Sign2Digital — Unterschrift angefordert','it'=>'Sign2Digital — Firma richiesta',
            ],
            'signing_reminder_body' => [
                'en'=>"Please review and sign your Sign2Digital quote.\n\nUpdated secure link (expires {expires}):\n{url}",
                'fr'=>"Veuillez examiner et signer votre devis Sign2Digital.\n\nNouveau lien sécurisé (expire le {expires}) :\n{url}",
                'de'=>"Bitte prüfen und unterschreiben Sie Ihr Sign2Digital-Angebot.\n\nAktualisierter sicherer Link (läuft ab: {expires}):\n{url}",
                'it'=>"Esamina e firma il tuo preventivo Sign2Digital.\n\nLink sicuro aggiornato (scade il {expires}):\n{url}",
            ],
            'signing_reminder_subject' => [
                'en'=>'Sign2Digital — Signature reminder','fr'=>'Sign2Digital — Rappel de signature',
                'de'=>'Sign2Digital — Unterschriftserinnerung','it'=>'Sign2Digital — Promemoria firma',
            ],
            'signed_addendum_body' => [
                'en'=>'Attached is your signed Sign2Digital addendum.',
                'fr'=>'Veuillez trouver votre avenant Sign2Digital signé en pièce jointe.',
                'de'=>'Ihr unterzeichneter Sign2Digital-Nachtrag ist beigefügt.',
                'it'=>'In allegato trovi il tuo addendum Sign2Digital firmato.',
            ],
            'signed_addendum_subject' => [
                'en'=>'Sign2Digital - Signed addendum {number}','fr'=>'Sign2Digital - Avenant signé {number}',
                'de'=>'Sign2Digital - Unterzeichneter Nachtrag {number}','it'=>'Sign2Digital - Addendum firmato {number}',
            ],
            'invoice_body' => [
                'en' => "Invoice {number}\n\n{message}\n\nReview your issued invoice at this private link (expires in 7 days):\n{url}",
                'fr' => "Facture {number}\n\n{message}\n\nConsultez votre facture émise via ce lien privé (valable 7 jours) :\n{url}",
                'de' => "Rechnung {number}\n\n{message}\n\nIhre ausgestellte Rechnung finden Sie unter diesem privaten Link (7 Tage gültig):\n{url}",
                'it' => "Fattura {number}\n\n{message}\n\nConsulta la fattura emessa tramite questo link privato (valido 7 giorni):\n{url}",
            ],
            'invoice_subject' => ['en'=>'Sign2Digital invoice {number}','fr'=>'Facture Sign2Digital {number}','de'=>'Sign2Digital Rechnung {number}','it'=>'Fattura Sign2Digital {number}'],
            'general_body' => [
                'en'=>"Sign2Digital document {number}\n\n{message}\n\nOpen the private document link (expires in 7 days):\n{url}",
                'fr'=>"Document Sign2Digital {number}\n\n{message}\n\nOuvrez le lien privé vers le document (valable 7 jours) :\n{url}",
                'de'=>"Sign2Digital-Dokument {number}\n\n{message}\n\nÖffnen Sie den privaten Dokumentenlink (7 Tage gültig):\n{url}",
                'it'=>"Documento Sign2Digital {number}\n\n{message}\n\nApri il link privato al documento (valido 7 giorni):\n{url}",
            ],
            'general_subject' => ['en'=>'Sign2Digital Document {number}','fr'=>'Document Sign2Digital {number}','de'=>'Sign2Digital-Dokument {number}','it'=>'Documento Sign2Digital {number}'],
            'acceptance_body' => [
                'en'=>'Please review the completed work and sign only if you agree: {url}',
                'fr'=>'Veuillez examiner les travaux terminés et signer uniquement si vous êtes d’accord : {url}',
                'de'=>'Bitte prüfen Sie die abgeschlossenen Arbeiten und unterschreiben Sie nur, wenn Sie einverstanden sind: {url}',
                'it'=>'Controlla i lavori completati e firma solo se sei d’accordo: {url}',
            ],
            'acceptance_subject' => ['en'=>'Sign2Digital Work Acceptance {number}','fr'=>'Réception des travaux Sign2Digital {number}','de'=>'Sign2Digital Bauabnahme {number}','it'=>'Accettazione lavori Sign2Digital {number}'],
            'report_body' => [
                'en'=>'Please find attached the approved {number} from Sign2Digital.',
                'fr'=>'Veuillez trouver ci-joint le rapport approuvé {number} de Sign2Digital.',
                'de'=>'Im Anhang finden Sie den freigegebenen Bericht {number} von Sign2Digital.',
                'it'=>'In allegato trovi il rapporto approvato {number} di Sign2Digital.',
            ],
        ];
        $pattern = $templates[$key][$code] ?? $templates[$key]['en'] ?? '';
        $safe = [];
        foreach ($values as $name => $value) {
            $safe['{'.$name.'}'] = (string) $value;
        }
        return strtr($pattern, $safe);
    }

    /** Static labels only; user-entered or API content must not be passed here. */
    private const LABELS = [
        'Invoice' => ['fr'=>'Facture','de'=>'Rechnung','it'=>'Fattura'],
        'Document' => ['fr'=>'Document','de'=>'Dokument','it'=>'Documento'],
        'Addendum' => ['fr'=>'Avenant','de'=>'Nachtrag','it'=>'Addendum'],
        'Site Report' => ['fr'=>'Rapport de chantier','de'=>'Baustellenbericht','it'=>'Rapporto di cantiere'],
        'Meeting Minutes' => ['fr'=>'Compte rendu de réunion','de'=>'Sitzungsprotokoll','it'=>'Verbale di riunione'],
        'Work Acceptance' => ['fr'=>'Réception des travaux','de'=>'Bauabnahme','it'=>'Accettazione lavori'],
        'Customer Response' => ['fr'=>'Réponse du client','de'=>'Kundenantwort','it'=>'Risposta del cliente'],
        'Date' => ['fr'=>'Date','de'=>'Datum','it'=>'Data'],
        'Date:' => ['fr'=>'Date :','de'=>'Datum:','it'=>'Data:'],
        'Due Date:' => ['fr'=>"Échéance :", 'de'=>'Fällig:', 'it'=>'Scadenza:'],
        'Project:' => ['fr'=>'Projet :','de'=>'Projekt:','it'=>'Progetto:'],
        'Type:' => ['fr'=>'Type :','de'=>'Typ:','it'=>'Tipo:'],
        'To:' => ['fr'=>'À :','de'=>'An:','it'=>'A:'],
        'Bill To:' => ['fr'=>'Facturer à :','de'=>'Rechnung an:','it'=>'Fatturare a:'],
        'Job' => ['fr'=>'Intervention','de'=>'Auftrag','it'=>'Lavoro'],
        'Client' => ['fr'=>'Client','de'=>'Kunde','it'=>'Cliente'],
        'Completion Date' => ['fr'=>'Date de fin','de'=>'Abschlussdatum','it'=>'Data di completamento'],
        'Cleaning / Restoration' => ['fr'=>'Nettoyage / remise en état','de'=>'Reinigung / Wiederherstellung','it'=>'Pulizia / ripristino'],
        'Completed Work' => ['fr'=>'Travaux terminés','de'=>'Abgeschlossene Arbeiten','it'=>'Lavori completati'],
        'Outstanding Issues' => ['fr'=>'Points en suspens','de'=>'Offene Punkte','it'=>'Problemi in sospeso'],
        'Required Remedial Work' => ['fr'=>'Travaux correctifs nécessaires','de'=>'Erforderliche Nacharbeiten','it'=>'Interventi correttivi necessari'],
        'Report Title' => ['fr'=>'Titre du rapport','de'=>'Berichtstitel','it'=>'Titolo del rapporto'],
        'Prepared By' => ['fr'=>'Préparé par','de'=>'Erstellt von','it'=>'Preparato da'],
        'Location' => ['fr'=>'Lieu','de'=>'Ort','it'=>'Luogo'],
        'Weather / Condition' => ['fr'=>'Météo / état','de'=>'Wetter / Zustand','it'=>'Meteo / condizioni'],
        'Site Progress' => ['fr'=>'Avancement du chantier','de'=>'Baufortschritt','it'=>'Avanzamento lavori'],
        'Notes & Observations' => ['fr'=>'Notes et observations','de'=>'Notizen und Beobachtungen','it'=>'Note e osservazioni'],
        'Issues / Risks' => ['fr'=>'Problèmes / risques','de'=>'Probleme / Risiken','it'=>'Problemi / rischi'],
        'Materials Delivery' => ['fr'=>'Livraison de matériaux','de'=>'Materiallieferung','it'=>'Consegna materiali'],
        'Labour & Time' => ['fr'=>'Main-d’œuvre et temps','de'=>'Arbeitszeit und Personal','it'=>'Manodopera e tempo'],
        'Summary' => ['fr'=>'Résumé','de'=>'Zusammenfassung','it'=>'Riepilogo'],
        'Agenda' => ['fr'=>'Ordre du jour','de'=>'Tagesordnung','it'=>'Ordine del giorno'],
        'Observations' => ['fr'=>'Observations','de'=>'Beobachtungen','it'=>'Osservazioni'],
        'Attendees' => ['fr'=>'Présents','de'=>'Teilnehmende','it'=>'Presenti'],
        'Absentees' => ['fr'=>'Absents','de'=>'Abwesende','it'=>'Assenti'],
        'Decisions' => ['fr'=>'Décisions','de'=>'Entscheidungen','it'=>'Decisioni'],
        'Outstanding Points' => ['fr'=>'Points en suspens','de'=>'Offene Punkte','it'=>'Punti in sospeso'],
        'Next Steps' => ['fr'=>'Prochaines étapes','de'=>'Nächste Schritte','it'=>'Prossimi passi'],
        'Action Items' => ['fr'=>'Actions à mener','de'=>'Aufgaben','it'=>'Attività da svolgere'],
        'Site Photos' => ['fr'=>'Photos du chantier','de'=>'Baustellenfotos','it'=>'Foto del cantiere'],
        'Photo evidence verified for this report.' => ['fr'=>'Photos justificatives vérifiées pour ce rapport.','de'=>'Fotobelege für diesen Bericht geprüft.','it'=>'Prove fotografiche verificate per questo rapporto.'],
        'Customer Signature' => ['fr'=>'Signature du client','de'=>'Unterschrift des Kunden','it'=>'Firma del cliente'],
        'Subtotal' => ['fr'=>'Sous-total','de'=>'Zwischensumme','it'=>'Subtotale'],
        'Total' => ['fr'=>'Total','de'=>'Gesamtbetrag','it'=>'Totale'],
        'VAT' => ['fr'=>'TVA','de'=>'MwSt.','it'=>'IVA'],
        'Tax' => ['fr'=>'Taxe','de'=>'Steuer','it'=>'Imposta'],
        'Description' => ['fr'=>'Description','de'=>'Beschreibung','it'=>'Descrizione'],
        'Qty' => ['fr'=>'Qté','de'=>'Menge','it'=>'Qtà'],
        'Unit Price' => ['fr'=>'Prix unitaire','de'=>'Stückpreis','it'=>'Prezzo unitario'],
        'Notes' => ['fr'=>'Notes','de'=>'Notizen','it'=>'Note'],
        'General Terms & Conditions' => ['fr'=>'Conditions générales','de'=>'Allgemeine Geschäftsbedingungen','it'=>'Termini e condizioni generali'],
        'Original Signed Document' => ['fr'=>'Document original signé','de'=>'Ursprünglich signiertes Dokument','it'=>'Documento originale firmato'],
        'Original quote' => ['fr'=>'Devis original','de'=>'Ursprüngliches Angebot','it'=>'Preventivo originale'],
        'Signed document' => ['fr'=>'Document signé','de'=>'Signiertes Dokument','it'=>'Documento firmato'],
        'Addendum revision' => ['fr'=>"Révision de l’avenant",'de'=>'Nachtragsversion','it'=>'Revisione addendum'],
        'Original SHA-256' => ['fr'=>'SHA-256 original','de'=>'Original-SHA-256','it'=>'SHA-256 originale'],
        'Original:' => ['fr'=>'Original :','de'=>'Original:','it'=>'Originale:'],
        'Reference:' => ['fr'=>'Référence :','de'=>'Referenz:','it'=>'Riferimento:'],
        'Related attachments (stored separately)' => ['fr'=>'Pièces jointes (stockées séparément)','de'=>'Anhänge (separat gespeichert)','it'=>'Allegati (archiviati separatamente)'],
        'Not specified' => ['fr'=>'Non précisé','de'=>'Nicht angegeben','it'=>'Non specificato'],
        'No priced additional work' => ['fr'=>'Aucun travail supplémentaire chiffré','de'=>'Keine bepreisten Zusatzarbeiten','it'=>'Nessun lavoro aggiuntivo prezzato'],
        'Approved by an authorized Sign2Digital user. The original approved PDF is retained unchanged.' => ['fr'=>"Approuvé par un utilisateur autorisé de Sign2Digital. Le PDF approuvé original reste inchangé.",'de'=>'Von einem autorisierten Sign2Digital-Nutzer genehmigt. Die ursprüngliche freigegebene PDF bleibt unverändert.','it'=>'Approvato da un utente Sign2Digital autorizzato. Il PDF approvato originale rimane invariato.'],
        'For customer review. This document is not accepted or signed until verified customer confirmation.' => ['fr'=>"Pour examen par le client. Ce document n’est accepté et signé qu’après confirmation vérifiée.",'de'=>'Zur Prüfung durch den Kunden. Die Annahme und Unterzeichnung erfolgen erst nach bestätigter Kundenzustimmung.','it'=>'Per la revisione del cliente. Il documento non è accettato o firmato fino alla conferma verificata.'],
        'The original signed quote remains unchanged. This separate addendum is not accepted until signed by the customer.' => ['fr'=>"Le devis original signé reste inchangé. Cet avenant distinct n’est accepté qu’après signature du client.",'de'=>'Das ursprünglich unterzeichnete Angebot bleibt unverändert. Dieser Nachtrag gilt erst nach Unterschrift des Kunden als angenommen.','it'=>'Il preventivo originale firmato rimane invariato. Questo addendum separato è accettato solo dopo la firma del cliente.'],
        'Page' => ['fr'=>'Page','de'=>'Seite','it'=>'Pagina'],
        'Payment Details' => ['fr'=>'Détails de paiement','de'=>'Zahlungsdetails','it'=>'Dettagli di pagamento'],
        'Payment QR unavailable' => ['fr'=>'QR de paiement indisponible','de'=>'Zahlungs-QR nicht verfügbar','it'=>'QR pagamento non disponibile'],
        'Received deposits' => ['fr'=>'Acomptes reçus','de'=>'Erhaltene Anzahlungen','it'=>'Acconti ricevuti'],
        'Other payments at issue' => ['fr'=>'Autres paiements à l’émission','de'=>'Weitere Zahlungen bei Ausstellung','it'=>'Altri pagamenti all’emissione'],
        'Outstanding at issue' => ['fr'=>'Restant dû à l’émission','de'=>'Bei Ausstellung offen','it'=>'Importo dovuto all’emissione'],
        'Customer Work Acceptance Response' => ['fr'=>'Réponse du client à la réception des travaux','de'=>'Kundenantwort zur Bauabnahme','it'=>'Risposta del cliente all’accettazione lavori'],
        'Work acceptance' => ['fr'=>'Réception des travaux','de'=>'Bauabnahme','it'=>'Accettazione lavori'],
        'Decision' => ['fr'=>'Décision','de'=>'Entscheidung','it'=>'Decisione'],
        'Customer' => ['fr'=>'Client','de'=>'Kunde','it'=>'Cliente'],
        'Signed decision date (UTC)' => ['fr'=>'Date de décision signée (UTC)','de'=>'Datum der unterschriebenen Entscheidung (UTC)','it'=>'Data decisione firmata (UTC)'],
        'Reservations' => ['fr'=>'Réserves','de'=>'Vorbehalte','it'=>'Riserve'],
        'Outstanding issues' => ['fr'=>'Points en suspens','de'=>'Offene Punkte','it'=>'Problemi in sospeso'],
        'Additional comments' => ['fr'=>'Commentaires supplémentaires','de'=>'Weitere Anmerkungen','it'=>'Commenti aggiuntivi'],
        'Optional rating' => ['fr'=>'Évaluation facultative','de'=>'Optionale Bewertung','it'=>'Valutazione facoltativa'],
        'Accepted with reservations' => ['fr'=>'Accepté avec réserves','de'=>'Unter Vorbehalt akzeptiert','it'=>'Accettato con riserve'],
        'Accepted' => ['fr'=>'Accepté','de'=>'Akzeptiert','it'=>'Accettato'],
        'None' => ['fr'=>'Aucun','de'=>'Keine','it'=>'Nessuno'],
        'Not provided' => ['fr'=>'Non fourni','de'=>'Nicht angegeben','it'=>'Non fornito'],
        'Customer Evidence Photo' => ['fr'=>'Photo justificative du client','de'=>'Kundenbelegfoto','it'=>'Foto prova del cliente'],
        'Work Acceptance Photo' => ['fr'=>'Photo de réception des travaux','de'=>'Abnahmefoto','it'=>'Foto accettazione lavori'],
        'Invoice email delivery is not configured.' => ['fr'=>"L’envoi de factures par e-mail n’est pas configuré.",'de'=>'Der Rechnungsversand per E-Mail ist nicht eingerichtet.','it'=>'L’invio delle fatture via e-mail non è configurato.'],
    ];
}
