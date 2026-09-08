#!/bin/sh
# Runs in the exact pair-root mount selected by pair_siterepo.sh. Git work
# shares authored bytes, not the runtime's secrets, journals or private proof.
set -eu
root="${1:?exact repository root required}"
uid="${2:?host uid required}"
gid="${3:?host gid required}"
transition="${4:?live or terminal transition required}"
case "$uid" in ''|*[!0-9]*) exit 1 ;; esac
case "$gid" in ''|*[!0-9]*) exit 1 ;; esac
[ -d "$root" ] && [ ! -L "$root" ] || exit 1
case "$transition" in live|terminal) ;; *) exit 1 ;; esac

if [ "$transition" = terminal ]; then
  # Reset/destroy already owns the complete disposable root. Changing owner
  # enables host cleanup of 0700 trees without ever publishing their bytes.
  chown -hR "$uid:$gid" "$root"
  exit 0
fi

# InitRepositoryBoundary.php's authored inventory and its sole tracked
# .wprism exception define this closed list. Unknown future runtime paths are
# private by default; adding authored paths requires an explicit review here.
for name in site.wprism.json state code media adapters .git .gitignore .gitattributes; do
  [ ! -L "$root/$name" ] || { echo 'repository authoring root is a symlink' >&2; exit 1; }
done
authority="$root/.wprism/authority/authorities.json"
if [ -e "$authority" ] || [ -L "$authority" ]; then
  [ -d "$root/.wprism" ] && [ ! -L "$root/.wprism" ] \
    && [ -d "$root/.wprism/authority" ] && [ ! -L "$root/.wprism/authority" ] \
    && [ -f "$authority" ] && [ ! -L "$authority" ] \
    || { echo 'repository authoring authority is not an ordinary file' >&2; exit 1; }
fi

chown "$uid:$gid" "$root"
chmod 0777 "$root"
for name in site.wprism.json state code media adapters .git .gitignore .gitattributes; do
  path="$root/$name"
  [ -e "$path" ] || continue
  # find does not follow links. A code/Git link cannot confer ownership or
  # permission authority over its destination outside this exact root.
  find "$path" \( -type d -o -type f \) -exec chown "$uid:$gid" {} +
  find "$path" \( -type d -o -type f \) -exec chmod ugo+rwX {} +
done
if [ -f "$authority" ]; then
  # Only the public authority needs host traversal. With no such file, leave
  # .wprism entirely untouched. Its private children always keep their owner
  # and modes; the shared-parent exception is sticky, never ordinary 0777.
  chown "$uid:$gid" "$root/.wprism" "$root/.wprism/authority" "$authority"
  chmod 1777 "$root/.wprism" "$root/.wprism/authority"
  chmod ugo+rw "$authority"
fi
