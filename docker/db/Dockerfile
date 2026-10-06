# Camagru database image: official postgres with locale tooling added.
# musl-locales ships the `locale` tool that initdb probes during the first
# initialisation. Without it the container prints two console warnings
# ("sh: locale: not found" / "no usable system locales were found"); with it
# the probe succeeds silently. See NOTES.md (accepted startup lines).
FROM postgres:16-alpine
RUN apk add --no-cache musl-locales
