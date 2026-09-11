import {useGetEventPublic} from "../../../../queries/useGetEventPublic.ts";
import {useParams} from "react-router";
import {useGetAttendeeTicketsPublic} from "../../../../queries/useGetAttendeeTicketsPublic.ts";
import {AttendeeTicket} from "../../../common/AttendeeTicket";
import {Attendee, EventOccurrence, Product} from "../../../../types.ts";
import {Container} from "@mantine/core";
import {t} from "@lingui/macro";
import {PoweredByFooter} from "../../../common/PoweredByFooter";
import {OnlineEventDetails} from "../../../common/OnlineEventDetails";
import {HomepageInfoMessage} from "../../../common/HomepageInfoMessage";
import classes from './AttendeeProductAndInformation.module.scss';

export const AttendeeProductAndInformation = () => {
    const {eventId, attendeeShortId} = useParams();
    const {data: event, isError: eventError} = useGetEventPublic(eventId);
    const {data: attendees, isError: attendeesError} = useGetAttendeeTicketsPublic(eventId, String(attendeeShortId));

    if (eventError || attendeesError) {
        return (
            <HomepageInfoMessage
                status="not_found"
                message={t`Ticket Not Found`}
                subtitle={t`We couldn't find the ticket you're looking for. The link may have expired or the ticket details may have changed.`}
            />
        );
    }

    if (!event || !attendees?.length) {
        return null;
    }

    const occurrences = attendees.reduce<EventOccurrence[]>((unique, attendee) => {
        const occurrence = attendee.event_occurrence;

        if (occurrence && !unique.some((existing) => existing.id === occurrence.id)) {
            unique.push(occurrence);
        }

        return unique;
    }, []);

    /**
     * (c) Hi.Events Ltd 2025
     *
     * PLEASE NOTE:
     *
     * Hi.Events is licensed under the GNU Affero General Public License (AGPL) version 3.
     *
     * You can find the full license text at: https://github.com/HiEventsDev/hi.events/blob/main/LICENCE
     *
     * In accordance with Section 7(b) of the AGPL, we ask that you retain the "Powered by Hi.Events" notice.
     *
     * If you wish to remove this notice, a commercial license is available at: https://hi.events/licensing
     */
    return (
        <Container>
            <h2 className={classes.title}>
                {attendees.length > 1 ? t`Your tickets for` : t`Your ticket for`} {event.title}
            </h2>

            <div className={classes.tickets}>
                {attendees.map((attendee) => (
                    <AttendeeTicket
                        key={attendee.short_id}
                        attendee={attendee as Attendee}
                        product={attendee.product as Product}
                        event={event}
                    />
                ))}
            </div>

            {occurrences.length === 0 && <OnlineEventDetails event={event} occurrence={null}/>}
            {occurrences.map((occurrence) => (
                <OnlineEventDetails key={occurrence.id} event={event} occurrence={occurrence}/>
            ))}

            <PoweredByFooter/>
        </Container>
    )
}

export default AttendeeProductAndInformation;
