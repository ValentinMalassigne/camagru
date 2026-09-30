# camagru

bonus can be done later : 

•  DNS (your side, the effective one): the sender domain valentinmalassigne.fr is yours, so in the Hostinger panel enable DKIM signing for it, and make sure SPF and DMARC records exist for the domain. Gmail weighs DKIM/SPF alignment and reputation most; with those in place it usually lands in the inbox. This is a sender-reputation issue, as you suspected — nothing in a hand-rolled PHP app fixes it.