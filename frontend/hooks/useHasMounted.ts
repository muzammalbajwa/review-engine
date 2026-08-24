import { useSyncExternalStore } from "react";

const subscribe = () => () => {};

/**
 * True once past hydration, false during SSR and the initial client
 * render — the standard React-endorsed way to ask "has the client taken
 * over yet" without the `useEffect(() => setMounted(true), [])`
 * anti-pattern (flagged by this project's own
 * eslint-plugin-react-hooks config as `set-state-in-effect`: an
 * unconditional setState at the top of an effect forces a real extra
 * "cascading" render). `useSyncExternalStore` renders the server value on
 * both the server and the first client pass — matching, no hydration
 * mismatch — then switches to the client value through its own internal
 * mechanism, not a second effect-triggered render.
 *
 * With no JS at all, this never runs past its `getServerSnapshot` value —
 * `useHasMounted()` simply stays `false` forever, which is exactly the
 * "nothing to subscribe to, nothing ever changes" case
 * `useSyncExternalStore` is built for.
 */
export function useHasMounted(): boolean {
  return useSyncExternalStore(
    subscribe,
    () => true,
    () => false
  );
}
