#!/bin/bash
# Génère les markers numérotés 1..50 à partir de path1.svg
# Remplace le placeholder #PP par le numéro
# Couleurs spéciales: #1 or, #2 argent, #3 bronze, #50 (dernier) rouge

SVG_SRC="path1.svg"
OUT_DIR="public/markers"
SIZE=82  # largeur en pixels (hauteur proportionnelle)
TOTAL=50

# Couleurs (remplacent #f9f9f9)
COLOR_GOLD="FFD700"
COLOR_SILVER="C0C0C0"
COLOR_BRONZE="CD7F32"
COLOR_LAST="ff5555"
COLOR_DEFAULT="f9f9f9"

mkdir -p "$OUT_DIR"

for i in $(seq 1 $TOTAL); do
    # Déterminer la couleur de remplacement
    if [ "$i" -eq 1 ]; then
        COLOR="$COLOR_GOLD"
    elif [ "$i" -eq 2 ]; then
        COLOR="$COLOR_SILVER"
    elif [ "$i" -eq 3 ]; then
        COLOR="$COLOR_BRONZE"
    elif [ "$i" -eq $TOTAL ]; then
        COLOR="$COLOR_LAST"
    else
        COLOR="$COLOR_DEFAULT"
    fi

    # Remplacer #PP par le numéro et la couleur du corps
    sed -e "s/#PP/#${i}/g" -e "s/#${COLOR_DEFAULT}/#${COLOR}/g" "$SVG_SRC" > "/tmp/marker_${i}.svg"
    # Copier le SVG dans le dossier markers pour debug
    cp "/tmp/marker_${i}.svg" "${OUT_DIR}/marker_${i}.svg"
    # Convertir en PNG
    rsvg-convert -w "$SIZE" "/tmp/marker_${i}.svg" -o "${OUT_DIR}/marker_${i}.png"
    rm "/tmp/marker_${i}.svg"
done

# Générer marker_last.png (rouge, sans numéro — texte #PP remplacé par vide)
sed -e "s/#PP/###/g" -e "s/#${COLOR_DEFAULT}/#${COLOR_LAST}/g" "$SVG_SRC" > "/tmp/marker_last.svg"
cp "/tmp/marker_last.svg" "${OUT_DIR}/marker_last.svg"
rsvg-convert -w "$SIZE" "/tmp/marker_last.svg" -o "${OUT_DIR}/marker_last.png"
rm "/tmp/marker_last.svg"

echo "Généré $TOTAL + marker_last dans ${OUT_DIR}/"
