# Camagru database image: official postgres with locale tooling added.
# musl-locales ships the `locale` tool that initdb probes during the first
# initialisation. Without it the container prints two console warnings
# ("sh: locale: not found" / "no usable system locales were found"); with it
# the probe succeeds silently. This is a correction, not a silencing: the
# warning described a missing tool, now the tool is there. See NOTES.md.
FROM postgres:16-alpine
RUN apk add --no-cache musl-locales
