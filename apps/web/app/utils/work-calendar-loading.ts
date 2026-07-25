export interface WorkCalendarLoadKeys {
  interval: string
  day: string
}

export interface WorkCalendarSnapshot<T> {
  key: string
  data: T
}

export function workCalendarLoadPlan(
  previous: WorkCalendarLoadKeys | undefined,
  next: WorkCalendarLoadKeys
): { interval: boolean, day: boolean } {
  return {
    interval: !previous || previous.interval !== next.interval,
    day: !previous || previous.day !== next.day
  }
}

export function workCalendarSnapshotForKey<T>(
  key: string,
  snapshot: WorkCalendarSnapshot<T> | null
): T | null {
  return snapshot?.key === key ? snapshot.data : null
}
