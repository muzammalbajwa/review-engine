"use client";

import { useState } from "react";

import { Button } from "@/components/ui/button";
import {
  inviteTeamMember,
  revokeInvite,
  updateMemberPermissions,
  type PendingInvite,
  type Permissions,
  type Team,
  type TeamMember,
} from "./actions";

const RESOURCE_LABELS: Record<keyof Permissions, string> = {
  contacts: "Contacts",
  templates: "Templates",
  reviews: "Reviews",
  analytics: "Analytics",
};

/**
 * Owner-only (SettingsTabs only renders this tab for an owner — enforced
 * again server-side by every action here hitting an EnsureTenantOwner-gated
 * endpoint regardless). Billing and team management themselves are never
 * shown as a toggle here — there's no checkbox for them because there's no
 * permission key by that name at all (.claude decision doc).
 */
export function TeamSection({ initialTeam }: { initialTeam: Team }) {
  const [members, setMembers] = useState(initialTeam.members);
  const [invites, setInvites] = useState(initialTeam.pending_invites);
  const [email, setEmail] = useState("");
  const [inviting, setInviting] = useState(false);
  const [inviteError, setInviteError] = useState<string | null>(null);

  async function handleInvite(e: React.FormEvent) {
    e.preventDefault();
    setInviting(true);
    setInviteError(null);

    const result = await inviteTeamMember(email);
    setInviting(false);

    if (result.status === "error") {
      setInviteError(result.message);
      return;
    }

    setInvites((prev) => [result.invite, ...prev]);
    setEmail("");
  }

  async function handleRevoke(inviteId: number) {
    const result = await revokeInvite(inviteId);

    if (result.status === "success") {
      setInvites((prev) => prev.filter((invite) => invite.id !== inviteId));
    }
  }

  async function handleTogglePermission(member: TeamMember, resource: keyof Permissions) {
    const result = await updateMemberPermissions(member.id, { [resource]: !member.permissions[resource] });

    if (result.status === "success") {
      setMembers((prev) => prev.map((m) => (m.id === member.id ? result.member : m)));
    }
  }

  return (
    <div className="flex max-w-2xl flex-col gap-8">
      <div>
        <p className="text-sm text-muted-foreground">
          Invite people to your team and control what each of them can see and do. Billing and team
          management always stay with you — they can&apos;t be granted to anyone else.
        </p>
      </div>

      <div className="flex flex-col gap-3">
        <p className="text-sm font-medium text-foreground">Team</p>
        <ul className="flex flex-col gap-2">
          {members.map((member) => (
            <li key={member.id} className="rounded-lg border border-border p-3">
              <div className="flex items-center justify-between gap-3">
                <div>
                  <p className="text-sm font-medium text-foreground">{member.name}</p>
                  <p className="text-xs text-muted-foreground">{member.email}</p>
                </div>
                {member.role === "owner" ? (
                  <span className="rounded-full bg-primary/10 px-2 py-0.5 text-xs font-medium text-primary">
                    Owner — full access
                  </span>
                ) : (
                  <span className="rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground">
                    Member
                  </span>
                )}
              </div>

              {member.role === "member" && (
                <div className="mt-3 flex flex-wrap gap-x-5 gap-y-2 border-t border-border pt-3">
                  {(Object.keys(RESOURCE_LABELS) as (keyof Permissions)[]).map((resource) => (
                    <label key={resource} className="flex items-center gap-2 text-sm text-foreground">
                      <input
                        type="checkbox"
                        checked={member.permissions[resource]}
                        onChange={() => handleTogglePermission(member, resource)}
                        className="size-4 rounded border-border text-primary outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                      />
                      {RESOURCE_LABELS[resource]}
                    </label>
                  ))}
                </div>
              )}
            </li>
          ))}
        </ul>
      </div>

      {invites.length > 0 && (
        <div className="flex flex-col gap-3">
          <p className="text-sm font-medium text-foreground">Pending invites</p>
          <ul className="flex flex-col gap-2">
            {invites.map((invite) => (
              <PendingInviteRow key={invite.id} invite={invite} onRevoke={() => handleRevoke(invite.id)} />
            ))}
          </ul>
        </div>
      )}

      <form onSubmit={handleInvite} className="flex flex-col gap-3 border-t border-border pt-5">
        <p className="text-sm font-medium text-foreground">Invite someone</p>
        <div className="flex flex-col gap-3 sm:flex-row">
          <input
            type="email"
            required
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            placeholder="teammate@example.com"
            className="h-9 flex-1 rounded-lg border border-border bg-background px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
          />
          <Button type="submit" disabled={inviting}>
            {inviting ? "Sending…" : "Send invite"}
          </Button>
        </div>
        {inviteError && (
          <p role="alert" className="text-sm text-destructive">
            {inviteError}
          </p>
        )}
        <p className="text-xs text-muted-foreground">
          They&apos;ll start with no access — grant permissions above once they&apos;ve joined.
        </p>
      </form>
    </div>
  );
}

function PendingInviteRow({ invite, onRevoke }: { invite: PendingInvite; onRevoke: () => void }) {
  const [revoking, setRevoking] = useState(false);

  async function handleClick() {
    setRevoking(true);
    onRevoke();
  }

  return (
    <li className="flex items-center justify-between gap-3 rounded-lg border border-border p-3 text-sm">
      <div>
        <p className="text-foreground">{invite.email}</p>
        <p className="text-xs text-muted-foreground">
          Invited {new Date(invite.invited_at).toLocaleDateString()}
        </p>
      </div>
      <button
        type="button"
        onClick={handleClick}
        disabled={revoking}
        className="rounded-sm text-xs font-medium text-destructive underline-offset-4 outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring/50"
      >
        {revoking ? "Revoking…" : "Revoke"}
      </button>
    </li>
  );
}
