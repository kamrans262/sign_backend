<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Supplement the existing English/French published revisions.
        // Never alter an admin-edited template, revision or already-sent inbox item.
        $examples = [['reminder.due','reminder',['reminder_title','due_date'],'Fällige Erinnerung: {reminder_title}','Ihre Erinnerung ist am {due_date} fällig.','Promemoria in scadenza: {reminder_title}','Il promemoria scade il {due_date}.'],['reminder.follow_up','reminder',['reminder_title','follow_up_date'],'Nachfass-Erinnerung','Bitte {reminder_title} bis {follow_up_date} nachverfolgen.','Promemoria di follow-up','Segui {reminder_title} entro il {follow_up_date}.'],['invoice.payment_due','invoice',['invoice_number'],'Zahlungserinnerung','Rechnung {invoice_number} erfordert Ihre Aufmerksamkeit.','Promemoria di pagamento','La fattura {invoice_number} richiede la tua attenzione.'],['equipment.loan_overdue','equipment',['equipment_name'],'Geräterückgabe überfällig','Die Rückgabe von {equipment_name} ist überfällig.','Restituzione attrezzatura in ritardo','Il prestito di {equipment_name} ha superato la data di restituzione.'],['document.signed','document',[],'Dokument unterschrieben','Ein unterschriebenes Dokument steht zur Prüfung bereit.','Documento firmato','Un documento firmato è pronto per la revisione.'],['customer.action_required','customer',[],'Kundenaktion erforderlich','Eine Kundenaktion erfordert Ihre Prüfung.','Azione del cliente richiesta','Un\'azione del cliente richiede la tua revisione.'],['system.general','system',[],'Sign2Digital-Aktualisierung','Sie haben eine neue Sign2Digital-Mitteilung.','Aggiornamento Sign2Digital','Hai un nuovo aggiornamento in Sign2Digital.']];
        foreach ($examples as [$key,$category,$vars,$deTitle,$deBody,$itTitle,$itBody]) {
            foreach (['de','it'] as $locale) {
                foreach (['in_app','push'] as $channel) {
                    $existing=DB::table('m20_notification_templates')
                        ->where('template_key',$key)->where('channel',$channel)
                        ->where('locale',$locale)->first();
                    if($existing)continue;
                    $title=$channel==='push'
                        ? ($locale==='de'?'Sign2Digital-Erinnerung':'Notifica Sign2Digital')
                        : ($locale==='de'?$deTitle:$itTitle);
                    $body=$channel==='push'
                        ? ($locale==='de'?'Öffnen Sie Sign2Digital für Einzelheiten.':'Apri Sign2Digital per i dettagli.')
                        : ($locale==='de'?$deBody:$itBody);
                    $now=now();
                    $id=DB::table('m20_notification_templates')->insertGetId([
                        'template_key'=>$key,'category'=>$category,'channel'=>$channel,
                        'locale'=>$locale,'allowed_variables'=>json_encode($vars,JSON_THROW_ON_ERROR),
                        'draft_title'=>$title,'draft_body'=>$body,'draft_revision'=>1,
                        'enabled'=>true,'created_at'=>$now,'updated_at'=>$now,
                    ]);
                    $revision=DB::table('m20_notification_template_revisions')->insertGetId([
                        'template_id'=>$id,'revision'=>1,'title'=>$title,'body'=>$body,
                        'action'=>'seed','created_at'=>$now,
                    ]);
                    DB::table('m20_notification_templates')->where('id',$id)
                        ->update(['published_revision_id'=>$revision]);
                }
            }
        }
    }

    public function down(): void
    {
        // Deliberately preserve published and edited notification content.
        // Reversing this migration must not delete customer-facing history.
    }
};
