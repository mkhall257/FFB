# Live Scores are provisional (Sleeper) and settle to Official Scores (nflverse)

A week's score has two distinct states. During NFL games, a cron job polls Sleeper to produce the **Live Score** — provisional and updating in-progress. Once nflverse publishes official stats (typically a day or two later), the system computes the **Official Score**, which supersedes the Live Score and locks the result.

The consequence a future reader must understand: **a score can change after Sunday night.** Official stat corrections (a reclassified catch, a yardage adjustment) mean the Official Score may differ from what Managers watched live. This is deliberate — we chose the excitement of a live ticker plus the correctness of authoritative final data over having a single always-final number. The UI must always label which state a score is in.
