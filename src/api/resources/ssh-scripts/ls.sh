# List one directory for the folder browser. Inputs: DIR, LIMIT.
# Entries are NUL-separated so names with spaces or newlines survive.
cd -- "$DIR" 2>/dev/null || { echo "__DEPLOYME_ERR__ cannot open"; exit 3; }

printf '__DEPLOYME_CWD__ %s\n' "$(pwd -P)"
if [ -w . ]; then echo "__DEPLOYME_CWD_WRITABLE__ 1"; else echo "__DEPLOYME_CWD_WRITABLE__ 0"; fi
df -B1 --output=source,fstype,size,avail,target . 2>/dev/null \
  | awk 'NR == 2 { printf "__DEPLOYME_DF__ %s %s %s %s %s\n", $1, $2, $3, $4, $5 }'

echo "__DEPLOYME_ENTRIES__"
find . -mindepth 1 -maxdepth 1 -printf '%y\t%Y\t%m\t%u\t%g\t%s\t%T@\t%f\0' 2>/dev/null | head -z -n "$LIMIT"
printf '\n__DEPLOYME_WRITABLE__\n'
find . -mindepth 1 -maxdepth 1 -type d -writable -printf '%f\0' 2>/dev/null
exit 0
