{{--
    The ARCS email layout (B13c). Every email uses it: markdown mailables (<x-mail::message>) and
    notifications (MailMessage) both render through this file. Header with the ARCS name, footer below.
    Inline styles only: email clients ignore stylesheets.
--}}
<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<tr>
<td class="header" data-arcs-mail="header" style="padding: 25px 0; text-align: center;">
<span style="color: #1F3864; font-size: 22px; font-weight: bold; letter-spacing: 2px;">ARCS</span><br>
<span style="color: #6b7280; font-size: 12px;">Airline Reservation &amp; Check-in System</span>
</td>
</tr>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<tr>
<td>
<table class="footer" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="content-cell" align="center" data-arcs-mail="footer">
<p style="color: #6b7280; font-size: 12px; text-align: center;">
ARCS · Airline Reservation &amp; Check-in System<br>
Sent by ARCS to airline staff. Please do not reply to this email.
</p>
</td>
</tr>
</table>
</td>
</tr>
</x-slot:footer>
</x-mail::layout>
