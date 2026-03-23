=== POTA Amelia Blocker - Form based ===
Stable tag: 1.0.4

== Fix: recurring foglalások ==
A recurring occurance-ok gyakran öröklik az internalNotes-t az elsőből.
Korábban a prefix miatt skip-eltük őket -> csak az első occurance kapott klónokat.

Most csak a KLÓNOKAT skip-eljük (CLONE vagy orig= marker).
