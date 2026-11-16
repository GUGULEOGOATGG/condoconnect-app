export type ScreenName = 'home' | 'tickets' | 'ticket' | 'newTicket' | 'chats' | 'chat' | 'profile' | 'editProfile' | 'notices' | 'notice' | 'notifications' | 'reservations' | 'newReservation' | 'providers' | 'community' | 'noticeEditor' | 'editCommunity' | 'adminUsers' | 'adminVerifications';
export type Route = { name: ScreenName; id?: number };
export type Navigate = (name: ScreenName, id?: number) => void;
