# 0003: Anonymous agents run as an account with no role

Date: 2026-11-19

The first live test returned "User authentication required for session creation": the adapter stores sessions per user. Rather than relaxing that check globally, anonymous requests to this one route run as a dedicated account with no role and no capabilities. The permission callback refuses to proceed if that account ever gains a role or capability. Every other WordPress endpoint stays exactly as locked as before (verified: `/wp/v2/users/me` still returns 401).
