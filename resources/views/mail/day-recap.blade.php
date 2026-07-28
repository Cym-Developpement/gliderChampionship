@php($pilot = $recap['pilot'])
<x-mail::message>
# Bonjour {{ $pilot->name }},

Voici votre récapitulatif du **jour {{ $day->day_number }}**
({{ $day->date->format('d/m/Y') }}) — {{ $competition->name }}.

@if($recap['glider'])
Planeur : **{{ $recap['glider']->reg }}**
{{ trim($recap['glider']->glider_brand . ' ' . $recap['glider']->glider_model) }}
@endif

@if(count($recap['turnpoints']) === 0)
Aucun point de virage n'a été validé pour cette journée.
@else
## Points de virage validés

<x-mail::table>
| Point de virage | Heure | Points |
|:----------------|:-----:|-------:|
@foreach($recap['turnpoints'] as $tp)
| {{ $tp['name'] }} | {{ $tp['validated_at'] ?? '—' }} | {{ $tp['points'] }} |
@endforeach
</x-mail::table>
@endif

# Total : {{ $recap['total'] }} points

@if($recap['adjusted'])
Ce total tient compte des ajustements décidés par l'organisation
(bonus ou pénalité) et ne correspond donc pas à la simple somme ci-dessus.
@endif

@if($recap['validated'])
Votre trace a été vérifiée : ce score est **définitif**.
@else
Ce score est **provisoire** tant que votre fichier IGC n'a pas été contrôlé.
@endif

Bons vols,<br>
L'organisation — {{ $competition->name }}
</x-mail::message>
