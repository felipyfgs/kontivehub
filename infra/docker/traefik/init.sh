#!/bin/sh
set -eu

file=/letsencrypt/acme.json

if [ -L "$file" ]; then
    echo "O armazenamento ACME não pode ser um link simbólico." >&2
    exit 1
fi

if [ ! -e "$file" ]; then
    : > "$file"
fi

chmod 600 "$file"
