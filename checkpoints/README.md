# Systeme de checkpoint

Systeme de checkpoint/sauvegarde independant de tout agent IA (Claude Code,
Codex, ou tout autre outil futur). Aucun nom d'agent n'apparait dans ce
dossier ni dans la logique : uniquement des notions generiques
(« operateur », « agent », « etape »).

## Vue d'ensemble

Trois couches :

1. **Etat** (`checkpoints/state.json`, `checkpoints/steps/*.json`,
   `checkpoints/log/*.json`, `checkpoints/backups/manifest.json`) - suivies
   par Git, source de verite auditable independamment de tout agent.
2. **Execution** (`checkpoint.ps1` + `checkpoints/lib/*.ps1`) - CLI unique,
   PowerShell 5.1, invocable par n'importe quel outil (humain ou agent).
3. **Enforcement** :
   - **Local** (`checkpoints/hooks/*.ps1`, installes via `install-hooks.ps1`
     dans `.git/hooks/`) - protection sur le poste de travail,
     **contournable par `git commit --no-verify`**. Le hook `post-commit`
     n'est en revanche jamais saute par `--no-verify` : il journalise donc
     tout commit qui n'a pas suivi la procedure normale.
   - **CI** (`.github/workflows/checkpoint-validate.yml`) - backstop
     serveur, rejoue independamment allowlist + tests sur chaque commit
     `checkpoint(step-...)` pousse. C'est la seule protection qui n'est pas
     contournable par l'agent (il ne controle pas GitHub Actions).

**Fonctionnement normal attendu du projet : tout `git commit --no-verify`
est interdit.** Les hooks locaux ne peuvent pas l'empecher techniquement ;
c'est une regle de discipline, appliquee visible par l'audit `post-commit`
et par la CI en cas de push.

## Commandes

```powershell
checkpoints\checkpoint.ps1 status
checkpoints\checkpoint.ps1 validate -Step 2.6.2
checkpoints\checkpoint.ps1 authorize -Step 2.6.2      # interactif uniquement
checkpoints\checkpoint.ps1 commit -Step 2.6.2
checkpoints\checkpoint.ps1 rollback -To checkpoint/2.6.1   # interactif uniquement
checkpoints\checkpoint.ps1 db -DbAction backup -File <sqlite> -Step 2.6.2
checkpoints\checkpoint.ps1 db -DbAction verify -File <sqlite>
checkpoints\checkpoint.ps1 db -DbAction restore -File <backup> -Target <sqlite>
```

Aucune de ces commandes n'execute `git push`.

## Trois formats de message acceptes par le hook `commit-msg`

1. `checkpoint(step-<id>): <resume>` - commit d'une etape metier suivie via
   `checkpoints/steps/<id>.json`, exige `state.json` en `ready_to_commit`
   pour cette etape exacte.
2. `WIP: <resume>` - travail en cours, hors checkpoint, toujours accepte
   sans verification de perimetre.
3. `<type>(checkpoint): <resume>` (`type` in `feat|fix|chore|docs|refactor|
   test|build|ci`) - commit officiel de **maintenance du systeme de
   checkpoint lui-meme** (ex. `feat(checkpoint): add agent-independent
   checkpoint system`). Accepte uniquement si **tous** les fichiers stages
   appartiennent au systeme (`Get-CheckpointSystemPathPatterns` dans
   `lib/Common.ps1` : `checkpoints/*`, `app/Console/Commands/CheckpointDb.php`,
   `.github/workflows/checkpoint-validate.yml`, `.gitignore`) - jamais un
   moyen de faire passer un changement metier sous couvert de ce format.
   `post-commit` applique la meme regle pour reconnaitre ce format comme
   legitime dans l'audit.

## Flux complet d'un checkpoint

1. **L'operateur humain** cree ou deverrouille `checkpoints/steps/<id>.json`
   (`locked:false`). Un agent ne peut pas produire cet etat lui-meme : ce
   fichier n'est jamais ecrit/deverrouille par `checkpoint.ps1`.
2. L'agent effectue le travail de code de l'etape.
3. L'agent lance `validate -Step <id>` : verifie l'allowlist (fichiers
   modifies vs `allowed_paths`), `git diff --check`, l'absence de
   marqueurs de conflit non resolus sur tout l'arbre, et execute
   `test_command`. Ecrit un log dans `checkpoints/log/` et met a jour
   `state.json` (`validated` ou `validation_failed`).
4. **L'operateur humain**, en session interactive, lance
   `authorize -Step <id>` : retape l'identifiant de l'etape pour confirmer.
   Refuse tout appel dont l'entree standard est redirigee.
5. `commit -Step <id>` : `git add` restreint a l'allowlist, puis ecrit un
   marqueur d'intention (`checkpoints/log/pending-checkpoint.json` :
   etape + hash d'arbre `git write-tree` de l'index deja stage) **avant**
   d'appeler `git commit` (message `checkpoint(step-<id>): <description>`,
   tag `checkpoint/<id>`). Refuse si l'etat n'est pas exactement
   `ready_to_commit` pour cette etape. Le marqueur est systematiquement
   supprime apres l'appel a `git commit`, que celui-ci reussisse ou non.
6. Le hook `pre-commit` revalide independamment l'allowlist ; `commit-msg`
   revalide le format du message et la coherence avec `state.json`.
7. `post-commit` (execute par `git commit` lui-meme, donc **avant** que
   `commit.ps1` ne reprenne la main) confirme la legitimite d'un commit
   `checkpoint(step-...)` en comparant le commit reellement produit
   (etape du message + `HEAD^{tree}`) au marqueur d'intention ecrit a
   l'etape 5 - jamais a `state.json`, qui n'est pas encore a jour a ce
   moment precis. Si legitime, ce hook ecrit lui-meme `state.json` a
   `committed` et supprime le marqueur. Sinon (aucun marqueur, ou
   marqueur ne correspondant pas), le commit est journalise dans
   `checkpoints/log/bypass-audit.jsonl`. `commit.ps1` ne reconcilie
   `state.json` que si ce hook n'a pas pu s'executer (absent/en erreur),
   avec un avertissement non bloquant dans le meme fichier.
8. La CI rejoue independamment le controle d'allowlist et de tests au
   push (mecanisme separe, sans rapport avec le marqueur ci-dessus qui
   n'existe que localement, jamais versionne).

## Mecanisme d'autorisation d'une etape

Trois verrous cumulatifs :

1. Le manifeste de l'etape suivante n'existe/n'est deverrouille que si
   l'operateur humain l'a explicitement cree ou modifie - aucun code de
   `checkpoint.ps1` ne bascule `locked` a `false`.
2. `authorize` refuse tout appel dont l'entree standard est redirigee
   (`[Console]::IsInputRedirected`), ce qui est la maniere normale dont un
   agent invoque une commande.
3. `authorize` exige de retaper l'identifiant complet de l'etape affiche a
   l'ecran (challenge), pas une simple confirmation `oui/non`.

**Limite assumee** : sur un poste ou l'agent et l'operateur partagent le
meme compte Windows et le meme shell, aucun mecanisme logiciel ne garantit
a 100% qu'un agent disposant d'un acces shell total ne simule pas ces
gestes. Ce systeme rend le contournement visible (nom d'utilisateur,
horodatage dans `state.json`) et non automatisable en une seule commande -
il ne remplace pas une revue humaine du log avant de faire confiance a une
autorisation.

Consequence directe : passer de `2.6.1` a `2.6.2` exige que
`checkpoints/steps/2.6.2.json` existe, soit deverrouille et reference
`depends_on: "2.6.1"` - il n'existe aucune regle de type « etape
suivante = numero + 1 » calculee automatiquement dans le code.

## Format des manifestes d'etape (`checkpoints/steps/<id>.json`)

Voir `checkpoints/steps/_template.json` pour le modele complet. Champs :
`step`, `substep`, `tier`, `description`, `depends_on`, `locked`,
`unlocked_by`, `unlocked_at`, `allowed_paths` (patterns `-like`
PowerShell : `*`/`?` simples, pas de glob `**`), `baseline_dirty_paths`
(voir ci-dessous), `test_command`, `requires_db_backup`.

### `baseline_dirty_paths` - isoler une etape des travaux preexistants

Le controle d'allowlist de `validate` compare `allowed_paths` a **tous**
les fichiers actuellement modifies/non suivis (`git status`), sans notion
de "depuis quand". Si un autre travail non commite (une etape precedente,
non liee) traine deja dans le repertoire de travail, `validate` le
signalerait a tort comme une violation de l'etape en cours.

`baseline_dirty_paths` (optionnel, tableau de chemins **exacts**, pas de
wildcard large) liste les fichiers deja sales **avant** que le travail de
cette etape ne commence. Un fichier y figurant est exclu du controle de
fichiers interdits, exactement comme `allowed_paths`, mais avec une
difference cruciale : `commit` ne `git add` jamais que `allowed_paths` -
un chemin de `baseline_dirty_paths` ne peut donc jamais se retrouver dans
le commit de l'etape, meme excuse par `validate`.

Ce champ est **statique** : ecrit une fois par l'operateur humain dans le
manifeste, jamais recalcule automatiquement par `validate`. Un recalcul
dynamique ("tout ce qui est sale maintenant et hors `allowed_paths`
devient baseline") annulerait la protection - un agent pourrait alors
faire passer n'importe quel fichier non autorise en le laissant simplement
trainer avant le premier `validate`.

Garde-fou : `validate` refuse (avec une erreur explicite) si un meme
chemin litteral apparait a la fois dans `allowed_paths` et
`baseline_dirty_paths` - un chemin ne peut pas etre a la fois "produit par
cette etape" et "bruit preexistant sans rapport".

Limite assumee : un fichier de `baseline_dirty_paths` encore davantage
modifie pendant le travail de l'etape reste excuse (aucune verification de
contenu/hash) - un raffinement possible mais non implemente ici.

## Format de l'etat (`checkpoints/state.json`)

`status` (`idle` / `validated` / `validation_failed` / `ready_to_commit` /
`committed`), `pending_step`, `last_committed_step`, `last_commit_sha`,
`authorized_by`, `authorized_at`, `last_validation_log`.

## Format de l'historique (`checkpoints/log/*.json`)

Un fichier par execution de `validate` (jamais reecrit) contenant :
`step`, `substep`, `timestamp`, `files_changed`, `checks` (allowlist,
diff_check, conflict_markers, tests avec commande/statut/sortie),
`verdict`. Un fichier par `commit` reussi contenant en plus : `commit`
(sha), `tag`, `files`, `db_backup` (reference au backup lie si
`requires_db_backup:true`), `status`. `checkpoints/log/bypass-audit.jsonl`
journalise en continu les commits hors procedure.

## Backup/restore DB

100% PDO (`app/Console/Commands/CheckpointDb.php`), aucune dependance a un
outil externe (`sqlite3`, `mysqldump` ne sont pas requis) :

- **backup** : `VACUUM INTO` - snapshot atomique et coherent meme en
  presence d'ecritures WAL en cours.
- **verify** : `PRAGMA integrity_check` + comptage de lignes par table.
- **restore** : verifie d'abord le backup (refuse si non exploitable),
  sauvegarde la cible existante avant de l'ecraser (`*.pre-restore-*.sqlite`),
  puis copie.
- **Lien backup <-> checkpoint** : `checkpoints/backups/manifest.json`
  associe `{step, file, sha256, source_commit_sha, created_at}`. Les
  fichiers `.sqlite` de backup eux-memes ne sont **jamais** versionnes
  (`.gitignore`) - seul le registre de metadonnees l'est.

## Procedure de rollback

1. Si des donnees doivent etre restaurees : `checkpoint.ps1 db -DbAction
   restore -File <backup> -Target <db>` **avant** le rollback de code, pour
   eviter un etat ou le code d'une etape tourne sur les donnees d'une
   autre.
2. `checkpoint.ps1 rollback -To <tag|sha>` (interactif, challenge de
   confirmation) - execute `git reset --hard`.

## Fonctionnement avec un agent de developpement (Claude Code, Codex, ou autre)

Aucune reference a un agent nomme dans le code. Le point d'entree est une
ligne de commande shell identique quel que soit l'outil qui l'invoque :
`checkpoint.ps1 validate -Step X`. Le changement d'agent (par exemple
Claude Code vers Codex) ne requiert aucune modification de ce systeme -
seule la configuration propre a cet agent (son fichier d'instructions) doit
lui indiquer d'utiliser cette CLI, ce qui est hors du perimetre de ce
dossier.

## Tests du systeme lui-meme

`checkpoints/tests/Run-SystemTests.ps1` - 10 scenarios, executes dans un
depot Git et une base SQLite jetables sous `$env:TEMP` (jamais sur le
depot reel ni `database/database.sqlite`) :

1. Violation d'allowlist detectee.
2. Test d'etape en echec bloque `validate`.
3. `validate` reussit quand tout est conforme.
4. `commit` refuse sans `authorize` prealable.
5. `authorize` rejette un appel non-interactif.
6. `commit` reussit une fois un etat `ready_to_commit` atteint.
7. `--no-verify` reussit mais `post-commit` journalise le contournement.
8. Sans `--no-verify`, un message non conforme est rejete.
9. Les logs d'historique contiennent tous les champs requis.
10. Cycle DB complet : backup -> verify (ok) -> corruption controlee ->
    verify (echec detecte) -> restore -> verify (ok).

## Limites techniques connues

1. `git commit --no-verify` contourne `pre-commit`/`commit-msg` par
   construction Git - seule la CI constitue un backstop non contournable
   par l'agent.
2. Separation agent/operateur imparfaite tant que les deux partagent le
   meme compte OS et le meme shell (voir section autorisation ci-dessus).
3. PowerShell 5.1 uniquement sur le poste de developpement actuel (pas de
   `pwsh` 7) - tous les scripts de ce dossier y sont compatibles.
4. `git diff --check` ne detecte pas les marqueurs de conflit deja
   committes (cas reel rencontre dans `README.md` du projet, commit
   `afeac20`) - d'ou le scan dedie sur tout l'arbre (`Test-ConflictMarkers`)
   en plus de `diff --check`.
5. La CI suppose un acces reseau/GitHub Actions actif et utilise l'action
   externe `shivammathur/setup-php` ; hors ligne ou sans remote, seul le
   niveau local s'applique, avec la limite du point 1.
6. Les patterns `allowed_paths` utilisent l'operateur PowerShell `-like`
   (wildcards simples `*`/`?`), pas un glob complet (`**` non supporte).
7. `checkpoints/state.json` et `checkpoints/steps/<id>.json` sont de
   simples fichiers JSON versionnés, sans protection cryptographique ni
   permission OS différenciée entre opérateur et agent : `status`,
   `authorized_by`, `authorized_at`, `locked` sont des marqueurs de
   workflow et d'audit, pas une preuve qu'une action humaine interactive
   a réellement eu lieu. Un accès en écriture au système de fichiers
   suffit techniquement à forger ces valeurs sans passer par
   `authorize`/`unlock`. Contournement rendu visible à la revue
   (`git log -p -- checkpoints/state.json checkpoints/steps/`), pas
   rendu impossible.
