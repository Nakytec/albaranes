<?php

namespace Modules\Albaranes\Services;

use App\Factory\QuoteInvitationFactory;
use App\Models\ClientContact;
use App\Models\Quote;
use App\Models\QuoteInvitation;
use App\Services\Email\Email;
use App\Services\Email\EmailObject;
use App\Utils\Traits\MakesHash;
use Illuminate\Support\Collection;

/**
 * Envía un albarán por correo al cliente.
 *
 * Reutiliza la maquinaria de email del core (plantilla, remitente, mailer y
 * adjunto PDF configurados en la empresa), pero ejecutándola DENTRO del bloque
 * de rótulos de albarán y de forma síncrona: así el PDF adjunto, el asunto y el
 * cuerpo salen como "Albarán" en vez de "Presupuesto". Con el envío en cola no
 * sería posible, porque el correo se construiría después de restaurar los
 * rótulos originales.
 */
class AlbaranMailer
{
    use MakesHash;

    public function __construct(private AlbaranLabels $labels = new AlbaranLabels())
    {
    }

    /**
     * @param  array<int, string>  $contact_ids  Contactos (hashed id) a los que enviar; vacío = los del cliente que reciben correo.
     * @return array<int, string>                Direcciones a las que se envió.
     */
    public function send(Quote $quote, array $contact_ids = []): array
    {
        $invitations = $this->invitationsFor($quote, $contact_ids);

        if ($invitations->isEmpty()) {
            throw new \RuntimeException('El cliente no tiene ningún contacto con dirección de correo a la que enviar el albarán.');
        }

        if ((int) $quote->status_id === Quote::STATUS_DRAFT) {
            $quote->service()->markSent()->save();
        }

        $quote = $quote->fresh();
        $sent = [];

        $this->labels->apply($quote, function () use ($quote, $invitations, &$sent) {
            foreach ($invitations as $invitation) {
                $mo = new EmailObject();
                $mo->entity_id = $quote->id;
                $mo->entity_class = Quote::class;
                $mo->invitation_id = $invitation->id;
                $mo->client_id = $quote->client_id;
                $mo->email_template_body = 'email_template_quote';
                $mo->email_template_subject = 'email_subject_quote';
                $mo->variables = $this->withoutViewButton();

                // Síncrono a propósito: el PDF y los textos se generan aquí,
                // con los rótulos de albarán todavía activos.
                Email::dispatchSync($mo, $quote->company);

                $invitation->sent_date = now();
                $invitation->email_status = null;
                $invitation->save();

                $sent[] = $invitation->contact->email;
            }

            $quote->last_sent_date = now();
            $quote->saveQuietly();
        });

        return $sent;
    }

    /**
     * Overrides de variables que dejan el correo sin el botón "Ver
     * presupuesto" ni su enlace.
     *
     * Ese botón lleva al portal del cliente, que muestra el documento como
     * presupuesto: los rótulos de albarán sólo viven durante el envío, no en
     * el portal. Para el receptor, el albarán es el PDF adjunto.
     *
     * @return array<string, array<string, string>>
     */
    private function withoutViewButton(): array
    {
        $empty = ['value' => '', 'label' => ''];

        return [
            '$view_link' => $empty,
            '$viewLink' => $empty,
            '$viewButton' => $empty,
            '$view_button' => $empty,
            '$view_url' => $empty,
        ];
    }

    /**
     * Invitaciones a las que enviar, creándolas si el contacto aún no tiene.
     *
     * @param  array<int, string>  $contact_ids
     * @return Collection<int, QuoteInvitation>
     */
    private function invitationsFor(Quote $quote, array $contact_ids): Collection
    {
        if (empty($contact_ids)) {
            if ($quote->invitations()->count() === 0) {
                $quote->service()->createInvitations()->save();
                $quote = $quote->fresh();
            }

            return $quote->invitations->filter(fn (QuoteInvitation $i) => $i->contact && $i->contact->email);
        }

        $contacts = ClientContact::query()
            ->where('client_id', $quote->client_id)
            ->whereIn('id', collect($contact_ids)->map(fn ($id) => $this->decodePrimaryKey($id))->all())
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->get();

        return $contacts->map(function (ClientContact $contact) use ($quote) {
            $invitation = QuoteInvitation::where('quote_id', $quote->id)
                ->where('client_contact_id', $contact->id)
                ->first();

            if (! $invitation) {
                $invitation = QuoteInvitationFactory::create($quote->company_id, $quote->user_id);
                $invitation->quote_id = $quote->id;
                $invitation->client_contact_id = $contact->id;
                $invitation->key = $this->createDbHash($quote->company->db);
                $invitation->save();
            }

            return $invitation;
        });
    }
}
