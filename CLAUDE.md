# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

FFB is a fantasy football site aimed at being easy to use for 12-year-olds running their own league. Planned stack:

- **Hosting**: ICDSoft web hosting
- **Database**: MySQL
- **Daily stats**: Sleeper API
- **Weekly stats**: nflverse CSV file downloads

The repository is a fresh scaffold — no application code exists yet.

## Agent skills

### Issue tracker

Issues and PRDs live as GitHub issues, managed with the `gh` CLI. See `docs/agents/issue-tracker.md`.

### Domain docs

Single-context layout — one `CONTEXT.md` + `docs/adr/` at the repo root. See `docs/agents/domain.md`.
