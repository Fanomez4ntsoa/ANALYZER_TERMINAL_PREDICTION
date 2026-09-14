{{--
    Note de bas de tableau des sélections : ce que dit la colonne Nature
    (docs/design-system.md, principe 3). Texte unique, à ne pas réécrire par page.
--}}
<p {{ $attributes->class('note') }}>
    <span class="text-p-mid font-medium">AJUST.</span> 1X2, DC, O/U 2.5 : les λ sont estimés sur ces cotes.
    Leur écart ne signale pas une opportunité, il mesure la contrainte de marché que le modèle n'a pas pu
    satisfaire : nul sur l'O/U 2.5, qui fixe le total ; sur le 1X2 et la DC, ce que deux lois de Poisson
    corrélées ne savent pas représenter. Sans couleur.
    <span class="text-p-mid font-medium">DÉRIVÉ</span> BTTS : calculé à partir des λ, seul son écart porte
    une information propre au modèle.
</p>
